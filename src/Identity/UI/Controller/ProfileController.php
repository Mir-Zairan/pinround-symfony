<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Application\AccountPermission;
use App\Identity\Application\ChangeTheme;
use App\Identity\Application\CurrentSession;
use App\Identity\Application\Profile;
use App\Identity\Application\ProfileProblem;
use App\Identity\Application\ShowProfile;
use App\Identity\Application\UpdateProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The profile page (FR-ID-07) and the avatar menu's theme switch. Both act only on the logged-in user, never on an id
 * from the request; every action asks OwnAccountVoter for AccountPermission::EDIT_OWN first (ADR-0013).
 */
final class ProfileController extends AbstractController
{
    public const string PROFILE_SAVED = 'Your profile is saved.';
    public const string NAME_BLANK = 'Enter your name.';
    public const string NAME_TOO_LONG = 'Use at most %d characters.';
    public const string TIME_ZONE_UNKNOWN = 'Pick a time zone from the list.';
    public const string THEME_UNKNOWN = 'Pick System, Light or Dark.';

    /** The Theme values with their labels, in display order; base.html.twig shows the same three in the avatar menu. */
    public const array THEME_OPTIONS = ['system' => 'System', 'light' => 'Light', 'dark' => 'Dark'];

    private const string PROFILE_CSRF_ID = 'identity_profile';
    // base.html.twig names it too, for the avatar menu's form.
    private const string THEME_CSRF_ID = 'identity_theme';

    public function __construct(
        private readonly ShowProfile $showProfile,
        private readonly UpdateProfile $updateProfile,
        private readonly ChangeTheme $changeTheme,
        private readonly CurrentSession $currentSession,
    ) {
    }

    #[Route('/me/profile', name: 'identity_profile', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AccountPermission::EDIT_OWN);
        $savedProfile = ($this->showProfile)($this->currentSession->userId());
        if (!$request->isMethod('POST')) {
            return $this->renderPage($savedProfile, $savedProfile);
        }

        $submittedProfile = new Profile($request->request->getString('name'), $request->request->getString('time_zone'), $request->request->getString('theme'));
        if (!$this->isCsrfTokenValid(self::PROFILE_CSRF_ID, $request->request->getString('_csrf_token'))) {
            return $this->renderPage($savedProfile, $submittedProfile, formError: LoginController::FORM_EXPIRED);
        }
        $problems = ($this->updateProfile)($this->currentSession->userId(), $submittedProfile->name, $submittedProfile->timeZone, $submittedProfile->theme);
        if ([] !== $problems) {
            return $this->renderPage($savedProfile, $submittedProfile, problems: $problems);
        }

        $this->addFlash('notice', self::PROFILE_SAVED);

        return $this->redirectToRoute('identity_profile');
    }

    /**
     * The avatar menu's switch. Its script asks with X-Requested-With and takes only 204 as saved, so the firewall's
     * redirect to the login page counts as a failure; a plain form post is redirected back to its page.
     */
    #[Route('/me/theme', name: 'identity_theme', methods: ['POST'])]
    public function changeTheme(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AccountPermission::EDIT_OWN);
        $userId = $this->currentSession->userId();
        if (!$this->isCsrfTokenValid(self::THEME_CSRF_ID, $request->request->getString('_csrf_token'))) {
            $savedProfile = ($this->showProfile)($userId);

            return $this->renderPage($savedProfile, $savedProfile, formError: LoginController::FORM_EXPIRED);
        }
        if (!($this->changeTheme)($userId, $request->request->getString('theme'))) {
            $savedProfile = ($this->showProfile)($userId);

            return $this->renderPage($savedProfile, $savedProfile, problems: [ProfileProblem::ThemeUnknown]);
        }

        if ($request->isXmlHttpRequest()) {
            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        return $this->redirect($this->returnPathOf($request));
    }

    /**
     * The page the switch was used on, as a path on this site; Home when the Referer is missing or points elsewhere, so
     * the redirect can never leave the site.
     */
    private function returnPathOf(Request $request): string
    {
        $referer = parse_url((string) $request->headers->get('referer'));
        $home = $this->generateUrl('web_home');
        if (false === $referer || !isset($referer['scheme'], $referer['host'])) {
            return $home;
        }
        $refererHost = $referer['host'].(isset($referer['port']) ? ':'.$referer['port'] : '');
        if ($referer['scheme'] !== $request->getScheme() || $refererHost !== $request->getHttpHost()) {
            return $home;
        }
        $path = $referer['path'] ?? '/';
        // "//host/…" and "/\host/…" are read by browsers as another site.
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return $home;
        }

        return $path.(isset($referer['query']) ? '?'.$referer['query'] : '');
    }

    /**
     * @param Profile              $savedProfile the profile as stored, for the time zone list
     * @param Profile              $shownProfile the values the form shows: the stored ones, or the ones just submitted
     * @param list<ProfileProblem> $problems
     */
    private function renderPage(Profile $savedProfile, Profile $shownProfile, ?string $formError = null, array $problems = []): Response
    {
        $timeZones = \DateTimeZone::listIdentifiers();
        // An old IANA name such as "US/Pacific" is not in the list but is the user's to keep.
        if (!\in_array($savedProfile->timeZone, $timeZones, true)) {
            array_unshift($timeZones, $savedProfile->timeZone);
        }
        $hasError = null !== $formError || [] !== $problems;

        return $this->render('@Identity/profile.html.twig', [
            'profile' => $shownProfile,
            'timeZones' => $timeZones,
            'themeOptions' => self::THEME_OPTIONS,
            'formError' => $formError,
            'maximumNameLength' => UpdateProfile::MAXIMUM_NAME_LENGTH,
            'nameError' => match (true) {
                \in_array(ProfileProblem::NameBlank, $problems, true) => self::NAME_BLANK,
                \in_array(ProfileProblem::NameTooLong, $problems, true) => \sprintf(self::NAME_TOO_LONG, UpdateProfile::MAXIMUM_NAME_LENGTH),
                default => null,
            },
            'timeZoneError' => \in_array(ProfileProblem::TimeZoneUnknown, $problems, true) ? self::TIME_ZONE_UNKNOWN : null,
            'themeError' => \in_array(ProfileProblem::ThemeUnknown, $problems, true) ? self::THEME_UNKNOWN : null,
        ], new Response(status: $hasError ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
