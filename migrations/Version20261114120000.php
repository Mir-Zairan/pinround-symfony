<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * cnv_message and cnv_reply, a ticket's thread, as tenant tables with row-level security. A message goes with its
 * ticket and a reply with its message (ON DELETE CASCADE), so the retention purge removes a closed ticket's thread by
 * removing the ticket. Message and Reply map them; MessageTicketKeyInSchema and ReplyMessageKeyInSchema put the keys
 * in the ORM schema. The index names leave out the leading organization_id, as tkt_ticket's do.
 */
final class Version20261114120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cnv_message and cnv_reply with row-level security';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE cnv_message (organization_id UUID NOT NULL, id UUID NOT NULL, ticket_id UUID NOT NULL,'
            .' author_id UUID NOT NULL, kind TEXT NOT NULL, to_user_id UUID DEFAULT NULL, body TEXT NOT NULL,'
            .' resolved_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, resolved_by UUID DEFAULT NULL,'
            .' edited_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, removed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,'
            .' created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            ." CONSTRAINT cnv_message_kind_check CHECK (kind IN ('comment', 'question')),"
            // A question asks exactly one person; a comment asks nobody.
            ." CONSTRAINT cnv_message_to_user_id_check CHECK ((kind = 'question') = (to_user_id IS NOT NULL)),"
            // Always resolved by someone: an answer closes a question, Resolve folds a comment; Message decides which may.
            .' CONSTRAINT cnv_message_resolved_check CHECK ((resolved_at IS NULL) = (resolved_by IS NULL)),'
            // Message::MAX_BODY_LENGTH, counted in characters as mb_strlen counts them.
            .' CONSTRAINT cnv_message_body_check CHECK (char_length(body) BETWEEN 1 AND 10000),'
            .' CONSTRAINT cnv_message_ticket_fkey FOREIGN KEY (organization_id, ticket_id) REFERENCES tkt_ticket (organization_id, id) ON DELETE CASCADE)',
        );
        $this->addSql('CREATE INDEX cnv_message_ticket_id_created_at_id_idx ON cnv_message (organization_id, ticket_id, created_at, id)');
        // One open question per ticket: the asked person holds the turn until an answer resolves it.
        $this->addSql(
            'CREATE UNIQUE INDEX cnv_message_ticket_id_open_question_key ON cnv_message (organization_id, ticket_id)'
            ." WHERE kind = 'question' AND resolved_at IS NULL AND removed_at IS NULL",
        );
        $this->addSql(
            'CREATE INDEX cnv_message_to_user_id_open_question_idx ON cnv_message (organization_id, to_user_id)'
            ." WHERE kind = 'question' AND resolved_at IS NULL AND removed_at IS NULL",
        );
        foreach (TenantTable::enableRls('cnv_message') as $sql) {
            $this->addSql($sql);
        }
        // No DELETE: a removed message keeps its row with removed_at, so the thread shows where it was.
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON cnv_message TO pinround_app');
        $this->addSql('GRANT SELECT ON cnv_message TO pinround_readonly');

        $this->addSql(
            'CREATE TABLE cnv_reply (organization_id UUID NOT NULL, id UUID NOT NULL, message_id UUID NOT NULL,'
            .' author_id UUID NOT NULL, body TEXT NOT NULL, is_answer BOOLEAN NOT NULL,'
            .' edited_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, removed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,'
            .' created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            // Reply::MAX_BODY_LENGTH, counted in characters as mb_strlen counts them.
            .' CONSTRAINT cnv_reply_body_check CHECK (char_length(body) BETWEEN 1 AND 10000),'
            .' CONSTRAINT cnv_reply_message_fkey FOREIGN KEY (organization_id, message_id) REFERENCES cnv_message (organization_id, id) ON DELETE CASCADE)',
        );
        $this->addSql('CREATE INDEX cnv_reply_message_id_created_at_id_idx ON cnv_reply (organization_id, message_id, created_at, id)');
        foreach (TenantTable::enableRls('cnv_reply') as $sql) {
            $this->addSql($sql);
        }
        // No DELETE, as for cnv_message.
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON cnv_reply TO pinround_app');
        $this->addSql('GRANT SELECT ON cnv_reply TO pinround_readonly');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cnv_reply');
        $this->addSql('DROP TABLE cnv_message');
    }
}
