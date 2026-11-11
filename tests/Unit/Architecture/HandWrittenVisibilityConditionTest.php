<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * No code but TicketVisibilitySql writes its own visibility condition (ADR-0013, P03.T2): the real src/ passes, and a
 * planted source with each banned form is reported at each line. The planted conditions are deliberate.
 */
final class HandWrittenVisibilityConditionTest extends TestCase
{
    private const string PLANTED = <<<'PHP'
        <?php
        $everyone = "SELECT id FROM tkt_ticket t WHERE t.visibility = 'all'";
        $restricted = 'SELECT count(*) FROM ins_ticket_turn WHERE visibility <> ?';
        $listed = "SELECT id FROM tkt_ticket WHERE organization_id = ?
            AND tt.VISIBILITY in ('all', 'team')";
        $reversed = "WHERE 'team' = t.visibility";
        $dql = 'SELECT t FROM Ticket t WHERE t.visibility != :hidden';
        $interpolated = "WHERE {$alias}.visibility IS NOT NULL";
        $this->visibility = $visibility;
        $columns = ['visibility' => 'team'];
        // WHERE t.visibility = 'all' in a comment is not SQL that runs.
        $copied = 'SELECT visibility, team_id FROM tkt_ticket';
        $cast = "SELECT id FROM tkt_ticket t WHERE t.visibility::text = 'all'";
        $switched = "SELECT CASE t.visibility WHEN 'all' THEN 1 ELSE 0 END FROM tkt_ticket t";
        $restrictedTickets = $this->tickets->findBy(['visibility' => 'team']);
        $oneRestricted = $this->tickets->findOneBy(['project' => $project, 'visibility' => 'team']);
        $restrictedCount = $this->tickets->count(array('visibility' => 'team'));
        $criteria = Criteria::create()->where(Criteria::expr()->eq('visibility', 'team'));
        $excluded = <<<'SQL'
            SELECT id FROM tkt_ticket t
            WHERE t.visibility NOT IN ('team')
            SQL;
        PHP;

    #[Test]
    public function theRepositoryHasNoHandWrittenVisibilityCondition(): void
    {
        self::assertSame([], HandWrittenVisibilityConditions::inRepository(self::repositoryRoot()));
    }

    #[Test]
    public function eachPlantedConditionIsReportedAtItsLine(): void
    {
        self::assertSame(
            [
                'Planted.php:2: visibility =',
                'Planted.php:3: visibility <>',
                'Planted.php:5: VISIBILITY in',
                'Planted.php:6: = t.visibility',
                'Planted.php:7: visibility !=',
                'Planted.php:8: visibility IS',
                'Planted.php:13: visibility::text =',
                'Planted.php:14: CASE t.visibility WHEN',
                "Planted.php:15: 'visibility' =>",
                "Planted.php:16: 'visibility' =>",
                "Planted.php:17: 'visibility' =>",
                "Planted.php:18: eq('visibility'",
                'Planted.php:21: visibility NOT IN',
            ],
            HandWrittenVisibilityConditions::inSource('Planted.php', self::PLANTED),
        );
    }

    /**
     * Each allowed file exists and does compare the column, so the allow-list holds no stale entry and the scan does
     * find conditions in real code.
     */
    #[Test]
    public function eachAllowedFileHoldsAConditionTheScanFinds(): void
    {
        foreach (array_keys(HandWrittenVisibilityConditions::ALLOWED) as $path) {
            $source = file_get_contents(self::repositoryRoot().'/'.$path);
            self::assertIsString($source, $path);
            self::assertNotSame([], HandWrittenVisibilityConditions::inSource($path, $source), $path);
        }
    }

    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
