<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * A modal dialog with an optional trigger. Blocks: "trigger" (the button that opens it), "content" and "actions".
 */
#[AsTwigComponent]
final class Dialog
{
    /** Becomes the id of the <dialog> and, with "-title", of its heading; the caller keeps it unique on the page. */
    public string $id = '';

    public string $title = '';

    /** Opens the dialog as soon as it is on the page, for a dialog that arrives with no trigger of its own (a Turbo Stream answer). */
    public bool $openOnConnect = false;

    #[PostMount]
    public function assertIsValid(): void
    {
        if (1 !== preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $this->id)) {
            throw new \InvalidArgumentException('A Dialog needs an id that starts with a letter and holds only letters, digits, "-" and "_".');
        }
        if ('' === trim($this->title)) {
            throw new \InvalidArgumentException('A Dialog needs a non-empty title.');
        }
    }
}
