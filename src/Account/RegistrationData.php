<?php

declare(strict_types=1);

namespace App\Account;

use App\Money\Currencies;
use Symfony\Component\Validator\Constraints as Assert;

/** Sign-up form data (messages are the design's copy). */
final class RegistrationData
{
    public const PASSWORD_MIN = 8;

    #[Assert\NotBlank(message: 'Enter your email address')]
    #[Assert\Email(message: 'Enter a valid email address, like name@example.com', mode: Assert\Email::VALIDATION_MODE_HTML5)]
    #[Assert\Length(max: 180, maxMessage: 'Use 180 characters or fewer')]
    public ?string $email = null;

    #[Assert\NotBlank(message: 'Create a password', normalizer: null)]
    #[Assert\Length(min: self::PASSWORD_MIN, max: 4096, minMessage: 'Password must be at least 8 characters')]
    public ?string $password = null;

    #[Assert\NotBlank(message: 'Choose your account currency')]
    #[Assert\Choice(callback: [Currencies::class, 'codes'], message: 'Choose your account currency')]
    public ?string $currency = null;
}
