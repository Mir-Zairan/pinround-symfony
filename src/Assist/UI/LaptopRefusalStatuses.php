<?php

declare(strict_types=1);

namespace App\Assist\UI;

use App\Assist\Api\LocalPackageRefused;
use App\Assist\Api\LocalResultRefused;
use App\Assist\Application\Policy\PolicyRefusal;
use Symfony\Component\HttpFoundation\Response;

/**
 * The HTTP status the laptop routes answer each refusal of LocalAi with (ADR-0042). A code no case names is refused as
 * forbidden: a new refusal fails closed, never as a server error and never as a success.
 */
final class LaptopRefusalStatuses
{
    /**
     * cannot_see_ticket is not found, so an unknown ticket and another team's or organization's read alike; a cap is
     * too many requests; a policy refusal is forbidden.
     */
    public static function ofPackageRefusal(LocalPackageRefused $refusal): int
    {
        return match ($refusal->refusalCode) {
            LocalPackageRefused::CANNOT_SEE_TICKET => Response::HTTP_NOT_FOUND,
            LocalPackageRefused::TOO_MANY_OPEN_PACKAGES, LocalPackageRefused::TOO_MANY_PACKAGES => Response::HTTP_TOO_MANY_REQUESTS,
            PolicyRefusal::AiOff->value, PolicyRefusal::LeadsOnly->value, PolicyRefusal::CloudOnly->value => Response::HTTP_FORBIDDEN,
            default => Response::HTTP_FORBIDDEN,
        };
    }

    /**
     * ADR-0042's table: package_not_found 404, package_gone 410, result_rejected 422, a policy refusal 403.
     */
    public static function ofResultRefusal(LocalResultRefused $refusal): int
    {
        return match ($refusal->refusalCode) {
            LocalResultRefused::PACKAGE_NOT_FOUND => Response::HTTP_NOT_FOUND,
            LocalResultRefused::PACKAGE_GONE => Response::HTTP_GONE,
            LocalResultRefused::RESULT_REJECTED => Response::HTTP_UNPROCESSABLE_ENTITY,
            PolicyRefusal::AiOff->value, PolicyRefusal::LeadsOnly->value, PolicyRefusal::CloudOnly->value => Response::HTTP_FORBIDDEN,
            default => Response::HTTP_FORBIDDEN,
        };
    }
}
