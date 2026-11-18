# Deliberate Symfony AI use outside Assist's Platform folder

Only `App\Assist\Infrastructure\Platform` may use `Symfony\AI\*`. Assist's own `Infrastructure\Prompt\PromptMessages`
and Ticketing's `Infrastructure\SummaryMessages` use it on purpose, although both layers may use other vendor code.
`SymfonyAiImportRuleTest` needs them. Do not "fix" the imports.
