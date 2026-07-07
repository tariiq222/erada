<?php

namespace App\Modules\Surveys\Enums;

enum SurveyPrivacyMode: string
{
    case Identified = 'identified';
    case Confidential = 'confidential';
    case Anonymous = 'anonymous';

    public function masksRespondentIdentity(): bool
    {
        return $this !== self::Identified;
    }

    public function hidesRawAnswerValues(): bool
    {
        return $this !== self::Identified;
    }

    public function respondentDisplayName(): string
    {
        return match ($this) {
            self::Identified => '',
            self::Confidential => 'مجيب سري',
            self::Anonymous => 'مجيب مجهول',
        };
    }
}
