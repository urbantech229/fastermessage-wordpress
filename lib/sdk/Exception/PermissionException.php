<?php

declare(strict_types=1);

namespace FasterMessageWP\Sdk\Exception;

/** 403, envoi interdit : expéditeur, pays, destinataire retiré. */
class PermissionException extends ApiException
{
}
