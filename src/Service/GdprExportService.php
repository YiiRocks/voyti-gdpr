<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\Service;

use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Model\UserSessions;
use YiiRocks\Voyti\SocialAuth\Model\UserSocialAccount;

/**
 * Builds a GDPR data-export array from a user's profile, sessions, and optional social-account
 * records, filtered by the configured property list.
 */
final readonly class GdprExportService
{
    /** @param list<string> $exportProperties */
    public function __construct(
        private array $exportProperties,
    ) {}

    /** @return array<string, mixed> */
    public function run(User $user): array
    {
        $values = array_map(
            static function (string $property) use ($user): mixed {
                return match ($property) {
                    'email' => $user->getEmail(),
                    'username' => $user->getUsername(),
                    'userProfile.public_email' => $user->getProfile()?->getPublicEmail(),
                    'userProfile.name' => $user->getProfile()?->getName(),
                    'userProfile.gravatar_email' => $user->getProfile()?->getGravatarEmail(),
                    'userProfile.location' => $user->getProfile()?->getLocation(),
                    'userProfile.website' => $user->getProfile()?->getWebsite(),
                    'userProfile.bio' => $user->getProfile()?->getBio(),
                    'userProfile.birthday' => $user->getProfile()?->getBirthday()?->format('Y-m-d'),
                    'userSessions' => array_map(
                        static fn(UserSessions $entry): array => [
                            'ip' => $entry->getIp(),
                            'user_agent' => $entry->getUserAgent(),
                            'created_at' => $entry->getCreatedAt(),
                            'updated_at' => $entry->getUpdatedAt(),
                        ],
                        UserSessions::findByUserId($user->getIdOrZero()),
                    ),
                    /**
                     * @infection-ignore-all Whether yiirocks/voyti-social-auth is installed is fixed
                     * for the whole test process (this package's own require-dev always has it
                     * present), so a mutant on this condition has no behavioural effect the suite can
                     * observe - the "not installed" branch can only be exercised by actually running
                     * without the optional package, not from within this test run.
                     */
                    'userSocialAccount' => class_exists(UserSocialAccount::class) ? array_map(
                        static fn(UserSocialAccount $account): array => [
                            'provider' => $account->getProvider(),
                            'username' => $account->getUsername(),
                            'email' => $account->getEmail(),
                            'created_at' => $account->getCreatedAt(),
                            'data' => $account->getDecodedData(),
                        ],
                        UserSocialAccount::findByUserId($user->getIdOrZero()),
                    ) : null,
                    default => null,
                };
            },
            $this->exportProperties,
        );

        /** @var array<array-key, mixed> $data */
        return array_filter(
            array_combine($this->exportProperties, $values),
            static fn(mixed $v): bool => $v !== null,
        );
    }
}
