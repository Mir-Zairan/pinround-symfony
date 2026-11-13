<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\Tests\Integration\Web\RendersTwigComponents;
use App\Ticketing\UI\Component\NextStepBox;
use App\Ticketing\UI\NextStep;
use App\Ticketing\UI\PersonToWaitOn;
use App\Ticketing\UI\TicketMoveController;
use App\Workflow\Api\MoveChoice;
use App\Workflow\Api\MoveChoiceGroup;
use App\Workflow\Api\MoveTarget;
use App\Workflow\Api\StepView;
use App\Workflow\Api\TransitionView;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * The "Your next step" box as it renders a NextStep: it only shows what NextStepChoices worked out.
 */
final class NextStepBoxComponentTest extends KernelTestCase
{
    use RendersTwigComponents;

    private const string KEY = 'SHOP-7';
    private const int VERSION = 4;
    private const string TODO_ID = '0197a3c0-0000-7000-8000-000000000001';
    private const string PROGRESS_ID = '0197a3c0-0000-7000-8000-000000000002';
    private const string PAIRING_ID = '0197a3c0-0000-7000-8000-000000000003';
    private const string INPUT_ID = '0197a3c0-0000-7000-8000-000000000004';
    private const string HOLD_ID = '0197a3c0-0000-7000-8000-000000000005';
    private const string MIA_ID = '0197a3c0-0000-7000-8000-0000000000a1';
    private const string OMAR_ID = '0197a3c0-0000-7000-8000-0000000000a2';

    protected function setUp(): void
    {
        self::bootKernel();
        $request = Request::create('https://localhost/t/'.self::KEY);
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('request_stack')->pop();
        parent::tearDown();
    }

    #[Test]
    public function theSentenceAndTheRuleNoteShowUnderTheLabel(): void
    {
        $box = $this->render(new NextStep('Test it.', [], 'Team rule: only the tester moves a ticket to Done.', []));

        self::assertSame('Your next step', $box->filter('.next-step-box__label')->text());
        self::assertSame('Test it.', $box->filter('.next-step-box__sentence')->text());
        self::assertSame('Team rule: only the tester moves a ticket to Done.', $box->filter('.next-step-box__rule-note')->text());
    }

    #[Test]
    public function aBoxWithNoVerbNoteOrStepsShowsOnlyItsSentence(): void
    {
        $box = $this->render(new NextStep('Nothing. This ticket is done.', [], null, []));

        self::assertSame('Nothing. This ticket is done.', $box->filter('.next-step-box__sentence')->text());
        self::assertCount(0, $box->filter('form, details, .next-step-box__rule-note, .next-step-box__verbs'));
    }

    #[Test]
    public function eachVerbIsAPostFormToItsMoveWithTheFormTokenAndTheVersion(): void
    {
        $box = $this->render(new NextStep('Plan it.', [
            new TransitionView('plan_it', 'Plan it', true, MoveTarget::coreStep('todo')),
            new TransitionView('give_it_to', 'Give it to…', false, MoveTarget::coreStep('todo')),
            new TransitionView('put_on_hold', 'Put on hold', false, MoveTarget::coreStep('hold')),
        ], null, []));

        $forms = $box->filter('.next-step-box__verbs form');
        self::assertSame(
            [
                ['/t/SHOP-7/move/plan_it', 'post', 'Plan it', 'submit', 'btn btn-filled'],
                ['/t/SHOP-7/move/put_on_hold', 'post', 'Put on hold', 'submit', 'btn btn-outline'],
            ],
            $forms->each(static fn (Crawler $form): array => [$form->attr('action'), $form->attr('method'), $form->filter('button')->text(), $form->filter('button')->attr('type'), $form->filter('button')->attr('class')]),
        );
        $this->assertCarriesTokenAndVersionOnly($forms, []);
    }

    #[Test]
    public function noMoveListShowsWhenThereAreNoGroups(): void
    {
        $box = $this->render(new NextStep('Start work.', [new TransitionView('start_work', 'Start work', true, MoveTarget::coreStep('progress'))], null, []));

        self::assertCount(1, $box->filter('form'));
        self::assertCount(0, $box->filter('details'));
    }

    #[Test]
    public function theMoveListShowsEachGroupInOrderWithAFormPerStep(): void
    {
        $box = $this->render($this->leadsNextStep());

        $moveList = $box->filter('details.next-step-box__move-to');
        self::assertSame('Move to another step…', $moveList->filter('summary')->text());
        self::assertSame(
            [['To do', ['To do']], ['Doing', ['In progress', 'Pairing']], ['Waiting', ['Needs input', 'On hold']]],
            $moveList->filter('.next-step-box__group')->each(static fn (Crawler $group): array => [
                $group->filter('.next-step-box__group-label')->text(),
                $group->filter('button.chip')->each(static fn (Crawler $chip): string => $chip->text()),
            ]),
        );
        $forms = $moveList->filter('form[method=post]');
        self::assertSame(
            ['/t/SHOP-7/move-to/'.self::TODO_ID, '/t/SHOP-7/move-to/'.self::PROGRESS_ID, '/t/SHOP-7/move-to/'.self::PAIRING_ID, '/t/SHOP-7/move-to/'.self::INPUT_ID, '/t/SHOP-7/move-to/'.self::HOLD_ID],
            $forms->each(static fn (Crawler $form): string => (string) $form->attr('action')),
        );
        self::assertSame(['post'], array_values(array_unique($forms->each(static fn (Crawler $form): string => (string) $form->attr('method')))));
    }

    #[Test]
    public function theCurrentStepIsPressedDisabledAndMarkedAsTheCurrentStep(): void
    {
        $chips = $this->render($this->leadsNextStep())->filter('details button.chip');

        self::assertSame(
            [
                ['To do', 'false', null, null],
                ['In progress', 'true', '', 'step'],
                ['Pairing', 'false', null, null],
                ['Needs input', 'false', null, null],
                ['On hold', 'false', null, null],
            ],
            $chips->each(static fn (Crawler $chip): array => [$chip->text(), $chip->attr('aria-pressed'), $chip->attr('disabled'), $chip->attr('aria-current')]),
        );
        self::assertSame(['submit'], array_values(array_unique($chips->each(static fn (Crawler $chip): string => (string) $chip->attr('type')))));
    }

    #[Test]
    public function onlyTheNeedsInputFormCarriesTheEmptyWaitingOnFieldsAndItsChipOpensTheDialog(): void
    {
        $forms = $this->render($this->leadsNextStep())->filter('details form[method=post]');

        $needsInputForm = $forms->filter('[data-waiting-on-target="moveForm"]');
        self::assertCount(1, $needsInputForm);
        self::assertSame('/t/SHOP-7/move-to/'.self::INPUT_ID, $needsInputForm->attr('action'));
        self::assertSame('true', $needsInputForm->attr('data-turbo'));
        $this->assertCarriesTokenAndVersionOnly($needsInputForm, [TicketMoveController::WAITING_ON_USER_FIELD => '', TicketMoveController::WAITING_ON_LABEL_FIELD => '']);
        $chip = $needsInputForm->filter('button.chip');
        self::assertSame(['submit', 'dialog', 'dialog#open:prevent'], [$chip->attr('type'), $chip->attr('aria-haspopup'), $chip->attr('data-action')]);
        $otherForms = $forms->reduce(static fn (Crawler $form): bool => null === $form->attr('data-waiting-on-target'));
        $this->assertCarriesTokenAndVersionOnly($otherForms, []);
        self::assertCount(0, $otherForms->filter('[aria-haspopup], [data-action]'));
    }

    #[Test]
    public function theDialogListsTheTicketsPeopleThenSomeoneElseWithTheFirstPersonChosen(): void
    {
        $box = $this->render($this->leadsNextStep([
            new PersonToWaitOn(self::MIA_ID, 'Mia', 'Reporter · Tester'),
            new PersonToWaitOn(self::OMAR_ID, 'Omar', 'Assignee'),
        ]));

        $dialog = $box->filter('[data-controller="waiting-on"] dialog#needs-input-'.self::INPUT_ID);
        self::assertCount(1, $box->filter('dialog'));
        self::assertCount(1, $dialog);
        self::assertSame('Move to Needs input', $dialog->filter('#needs-input-'.self::INPUT_ID.'-title')->text());
        $form = $dialog->filter('form');
        self::assertSame(['dialog', 'needs-input-'.self::INPUT_ID.'-form'], [$form->attr('method'), $form->attr('id')]);
        self::assertSame('Who does it wait on?', $form->filter('fieldset > legend')->text());
        self::assertSame(
            [['Mia · Reporter · Tester', self::MIA_ID, ''], ['Omar · Assignee', self::OMAR_ID, null], ['Someone else', '', null]],
            $form->filter('label')->each(static fn (Crawler $label): array => [$label->text(), $label->filter('input[type=radio]')->attr('value'), $label->filter('input[type=radio]')->attr('checked')]),
        );
        self::assertSame('choice someoneElse', $form->filter('label')->last()->filter('input')->attr('data-waiting-on-target'));
        $labelField = $form->filter('input[type=text]');
        self::assertSame(
            ['100', '', '.*\S.*', '', 'Who else it waits on', 'label'],
            [$labelField->attr('maxlength'), $labelField->attr('required'), $labelField->attr('pattern'), $labelField->attr('disabled'), $labelField->attr('aria-label'), $labelField->attr('data-waiting-on-target')],
        );
        self::assertSame(
            [['Cancel', 'button', 'btn btn-text', null], ['Move', 'submit', 'btn btn-filled', 'needs-input-'.self::INPUT_ID.'-form']],
            $dialog->filter('.dialog__actions button')->each(static fn (Crawler $button): array => [$button->text(), $button->attr('type'), $button->attr('class'), $button->attr('form')]),
        );
    }

    #[Test]
    public function withNoPersonToNameSomeoneElseIsChosenAndItsFieldIsOpen(): void
    {
        $form = $this->render($this->leadsNextStep())->filter('dialog form');

        self::assertSame(
            [['Someone else', '']],
            $form->filter('label')->each(static fn (Crawler $label): array => [$label->text(), $label->filter('input[type=radio]')->attr('checked')]),
        );
        self::assertNull($form->filter('input[type=text]')->attr('disabled'));
    }

    #[Test]
    public function theNeedsInputStepTheTicketIsInOpensNoDialog(): void
    {
        $box = $this->render($this->leadsNextStep([new PersonToWaitOn(self::MIA_ID, 'Mia', 'Reporter')], isNeedsInputCurrent: true));

        self::assertCount(0, $box->filter('dialog, [data-controller="waiting-on"], [aria-haspopup]'));
        $currentForm = $box->filter('form[action="/t/SHOP-7/move-to/'.self::INPUT_ID.'"]');
        self::assertSame('true', $currentForm->filter('button.chip')->attr('aria-pressed'));
        $this->assertCarriesTokenAndVersionOnly($currentForm, []);
    }

    /**
     * @param list<PersonToWaitOn> $peopleToWaitOn
     */
    private function leadsNextStep(array $peopleToWaitOn = [], bool $isNeedsInputCurrent = false): NextStep
    {
        $choice = static fn (string $id, string $name, string $groupCode, string $groupLabel, ?string $coreCode, bool $isCurrent = false): MoveChoice => new MoveChoice(
            new StepView($id, $name, $groupCode, $groupLabel, 0, null === $coreCode ? 'team' : 'core', $coreCode),
            $isCurrent,
        );

        return new NextStep('Nothing for you now. Omar’s turn.', [], null, [
            new MoveChoiceGroup('todo', 'To do', [$choice(self::TODO_ID, 'To do', 'todo', 'To do', 'todo')]),
            new MoveChoiceGroup('doing', 'Doing', [$choice(self::PROGRESS_ID, 'In progress', 'doing', 'Doing', 'progress', !$isNeedsInputCurrent), $choice(self::PAIRING_ID, 'Pairing', 'doing', 'Doing', null)]),
            new MoveChoiceGroup('wait', 'Waiting', [$choice(self::INPUT_ID, 'Needs input', 'wait', 'Waiting', 'input', $isNeedsInputCurrent), $choice(self::HOLD_ID, 'On hold', 'wait', 'Waiting', 'hold')]),
        ], $peopleToWaitOn);
    }

    /**
     * Each form's hidden fields are exactly a valid form token for the ticket's moves, the version, then $moreFields.
     *
     * @param array<string, string> $moreFields
     */
    private function assertCarriesTokenAndVersionOnly(Crawler $forms, array $moreFields): void
    {
        self::assertNotCount(0, $forms);
        $tokenManager = self::getContainer()->get('security.csrf.token_manager');
        $forms->each(static function (Crawler $form) use ($tokenManager, $moreFields): void {
            $fields = [];
            $form->filter('input')->each(static function (Crawler $input) use (&$fields): void {
                self::assertSame('hidden', $input->attr('type'));
                $fields[(string) $input->attr('name')] = (string) $input->attr('value');
            });
            $token = $fields[TicketMoveController::CSRF_FIELD] ?? '';
            self::assertTrue($tokenManager->isTokenValid(new CsrfToken(TicketMoveController::csrfIdOf(self::KEY), $token)), 'The form token is not one for the ticket\'s moves.');
            self::assertSame([TicketMoveController::CSRF_FIELD => $token, TicketMoveController::VERSION_FIELD => (string) self::VERSION, ...$moreFields], $fields);
        });
    }

    private function render(NextStep $nextStep): Crawler
    {
        return $this->renderTwigComponent(NextStepBox::class, ['ticketKey' => self::KEY, 'version' => self::VERSION, 'nextStep' => $nextStep])->crawler();
    }
}
