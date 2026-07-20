<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Wp01aOutboxClaimToken extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE outbox_messages
    ADD COLUMN claim_token CHAR(36) NULL AFTER locked_by
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE outbox_messages
    DROP COLUMN claim_token
SQL);
    }
}
