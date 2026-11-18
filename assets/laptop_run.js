// A run of an AI feature on the laptop (ai-architecture.md, Laptop mode protocol): pinround's prompt package, Ollama's
// text, pinround keeping that text as a proposal. Neither the ticket text nor the answer is logged.

// A CPU-only laptop may take a minute or two for a summary; this stays under the package's 5 minutes.
const GENERATE_TIMEOUT_MS = 180_000;
// Thinking models may put their reasoning before the answer; an unclosed tag means the answer was cut inside it.
const THINKING = /<think>[\s\S]*?(?:<\/think>|$)/g;

export const RUNNING_LINE = 'Running on this laptop. Ticket text stays on this laptop.';
export const FAILED_LINE = 'The AI answer failed. Try again.';
/** After Ollama did not answer where the cloud may take the press; not in the prototype. */
export const CLOUD_NEXT_LINE = 'Ollama on this laptop did not answer. Press again to use cloud AI.';
const UNREACHABLE_LINE ='pinround could not reach Ollama on this laptop. Use “Local AI” at the top to set it up.';
const TOO_SLOW_LINE = 'Ollama on this laptop took too long to answer. Try again, or use “Local AI” at the top to pick another model.';
const NO_SUMMARY_LINE = 'Ollama on this laptop gave no summary. Use “Local AI” at the top to check it.';

/** A run that ended with a sentence for the person. isOllamaSilent: Ollama could not be reached or did not answer in time. */
export class LaptopRunRefused extends Error {
  constructor(line, { isOllamaSilent = false } = {}) {
    super(line);
    this.line = line;
    this.isOllamaSilent = isOllamaSilent;
  }
}

/**
 * Summarizes the ticket on the laptop and has pinround keep the text as the ticket's laptop summary proposal. Throws
 * LaptopRunRefused with the sentence to show, or what the signal aborted with.
 *
 * @param {{packageUrl: string, resultUrl: string, generateUrl: string, csrfHeader: string, token: string, ticketId: string, model: string}} run
 * @param {AbortSignal} signal
 */
export async function summarizeOnLaptop({ packageUrl, resultUrl, generateUrl, csrfHeader, token, ticketId, model }, signal) {
  const headers = { [csrfHeader]: token };
  const promptPackage = await postToPinround(packageUrl, headers, { feature: 'summary', ticketId }, 200, signal);
  if (typeof promptPackage?.packageId !== 'string' || typeof promptPackage.instructions !== 'string' || typeof promptPackage.prompt !== 'string') {
    throw new LaptopRunRefused(FAILED_LINE);
  }
  const text = await generateOnLaptop(generateUrl, model, promptPackage, signal);
  await postToPinround(resultUrl, headers, { packageId: promptPackage.packageId, model, text }, 201, signal);
}

/**
 * Ollama's text for a prompt package, without its thinking. The request carries no cookie and no referrer: Ollama needs
 * neither. Throws LaptopRunRefused with the sentence to show, or what the signal aborted with.
 *
 * @param {{instructions: string, prompt: string, schema?: string}} promptPackage
 */
export async function generateOnLaptop(generateUrl, model, promptPackage, signal) {
  const timeout = AbortSignal.timeout(GENERATE_TIMEOUT_MS);
  const request = { model, system: promptPackage.instructions, prompt: promptPackage.prompt, stream: false, think: false };
  if (typeof promptPackage.schema === 'string') {
    request.format = JSON.parse(promptPackage.schema);
  }
  const silence = () => new LaptopRunRefused(timeout.aborted ? TOO_SLOW_LINE : UNREACHABLE_LINE, { isOllamaSilent: true });
  let response;
  try {
    response = await fetch(generateUrl, {
      method: 'POST',
      body: JSON.stringify(request),
      signal: AbortSignal.any([signal, timeout]),
      credentials: 'omit',
      cache: 'no-store',
      referrerPolicy: 'no-referrer',
    });
  } catch (failure) {
    throw signal.aborted ? failure : silence();
  }
  if (!response.ok) {
    throw new LaptopRunRefused(NO_SUMMARY_LINE);
  }
  let answer;
  try {
    answer = await response.json();
  } catch (failure) {
    if (signal.aborted) {
      throw failure;
    }
    throw timeout.aborted ? silence() : new LaptopRunRefused(NO_SUMMARY_LINE);
  }
  const text = typeof answer?.response === 'string' ? answer.response.replace(THINKING, '').trim() : '';
  if (text === '') {
    throw new LaptopRunRefused(NO_SUMMARY_LINE);
  }
  return text;
}

// The answer's JSON; a refusal throws with the sentence pinround answered, any other failure with FAILED_LINE.
async function postToPinround(url, headers, body, expectedStatus, signal) {
  let response;
  try {
    response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...headers },
      body: JSON.stringify(body),
      signal,
    });
  } catch (failure) {
    throw signal.aborted ? failure : new LaptopRunRefused(FAILED_LINE);
  }
  const answer = await response.json().catch(() => null);
  if (response.status !== expectedStatus) {
    throw new LaptopRunRefused(typeof answer?.message === 'string' && answer.message !== '' ? answer.message : FAILED_LINE);
  }
  return answer;
}
