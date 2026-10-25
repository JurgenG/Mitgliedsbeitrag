<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

/**
 * @class AdmidioPaymentMemberStore
 * @brief PaymentMemberStoreInterface on top of Admidio's User entity and the plugin's profile fields
 *
 * Saves with saveChangesWithoutRights(): the pay page and the webhook run without a logged-in user,
 * and Admidio otherwise refuses to save the plugin's disabled profile fields.
 * Needs common_function.php (normalize_fee()).
 */

namespace Plugins\MembershipFee\classes\Payment;

use Admidio\Infrastructure\Database;
use Admidio\ProfileFields\ValueObjects\ProfileFields;
use Admidio\Users\Entity\User;

final class AdmidioPaymentMemberStore implements PaymentMemberStoreInterface
{
    /**
     * @param Database      $db
     * @param ProfileFields $profileFields Profile field definitions of the organisation
     * @param int           $orgId         Organisation whose fields (FEE{org}, ...) are used
     */
    public function __construct(
        private readonly Database $db,
        private readonly ProfileFields $profileFields,
        private readonly int $orgId
    ) {
    }

    public function findMember(string $userUuid): ?PaymentMember
    {
        $user = $this->readUser($userUuid);

        if ($user === null) {
            return null;
        }

        return new PaymentMember(
            $userUuid,
            normalize_fee($user->getValue('FEE' . $this->orgId, 'database')),
            (string) $user->getValue('PAID' . $this->orgId, 'database') !== '',
            (string) $user->getValue('PAYTOKEN' . $this->orgId, 'database'),
            (string) $user->getValue('CONTRIBUTORY_TEXT' . $this->orgId, 'database')
        );
    }

    public function saveToken(string $userUuid, string $token): void
    {
        $user = $this->requireUser($userUuid);
        $user->setValue('PAYTOKEN' . $this->orgId, $token);
        $user->save();
    }

    public function markPaid(string $userUuid, string $paidDate, string $paymentId, string $provider, string $newToken): void
    {
        $user = $this->requireUser($userUuid);
        $user->setValue('PAID' . $this->orgId, $paidDate);
        $user->setValue('PAYMENT_ID' . $this->orgId, $paymentId);
        $user->setValue('PAYMENT_PROVIDER' . $this->orgId, $provider);
        $user->setValue('PAYTOKEN' . $this->orgId, $newToken);

        // like payments.php: once paid, a planned SEPA collection must not be executed as well
        $user->setValue('DUEDATE' . $this->orgId, '');
        $user->save();
    }

    /**
     * Reads a user by uuid, with rights to save the plugin's disabled fields
     * @param string $userUuid
     * @return User|null null if there is no such user
     */
    private function readUser(string $userUuid): ?User
    {
        $user = new User($this->db, $this->profileFields);

        if ($userUuid === '' || !$user->readDataByUuid($userUuid)) {
            return null;
        }
        $user->saveChangesWithoutRights();

        return $user;
    }

    /**
     * @param string $userUuid
     * @throws PaymentException if there is no such user
     */
    private function requireUser(string $userUuid): User
    {
        $user = $this->readUser($userUuid);

        if ($user === null) {
            throw new PaymentException('Unknown member');
        }

        return $user;
    }
}
