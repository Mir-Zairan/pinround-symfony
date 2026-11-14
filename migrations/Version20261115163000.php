<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Limits ntf_notification.kind to the kinds realtime-and-notifications.md › Notification kinds lists. digest_recipients
 * (Version20261115150000) reads kind across organizations, and a text column may be granted to pinround_resolver only
 * when a CHECK limits it to a fixed set of values or tenant-bound functions alone read its table
 * (TenantTable::allowResolverRead); with the set closed, that read can carry no tenant's text. Added on the partitioned
 * parent, so every partition, those to come included, gets it.
 */
final class Version20261115163000 extends AbstractMigration
{
    /** Written out here: a migration must not change when NotificationKind or BellKind later does. */
    private const array KINDS = ['ping', 'question', 'answer', 'reply', 'assigned', 'your_turn', 'check', 'round_shared', 'closed_duplicate'];

    public function getDescription(): string
    {
        return 'Limit ntf_notification.kind to the documented notification kinds';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(\sprintf(
            'ALTER TABLE ntf_notification ADD CONSTRAINT ntf_notification_kind_check CHECK (kind IN (%s))',
            implode(', ', array_map(static fn (string $kind): string => "'".$kind."'", self::KINDS)),
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ntf_notification DROP CONSTRAINT ntf_notification_kind_check');
    }
}
