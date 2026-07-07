<?php

namespace App\Modules\Projects\Exceptions;

class ProjectMemberAlreadyExistsException extends \RuntimeException
{
    public function __construct(string $message = 'هذا المستخدم عضو بالفعل في المشروع', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
