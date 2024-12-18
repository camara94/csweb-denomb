<?php

namespace AppBundle\Security;

use AppBundle\CSPro\User\User;
use AppBundle\CSPro\User\Role;
use AppBundle\CSPro\User\RoleDictionaryPermissions;
use AppBundle\CSPro\Dictionary;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Psr\Log\LoggerInterface;

/**
 * Description of DictionaryVoter
 *
 * @author savy
 */
class DictionaryVoter extends Voter {

    const DICTIONARY_OPERATIONS = 'data_all';
    const DATA_DOWNLOAD = 'data_all';
    const SYNC_UPLOAD = 'dictionary_sync_upload';
    const SYNC_DOWNLOAD = 'dictionary_sync_download';

    private $logger;

    public function __construct(Security $security, LoggerInterface $logger) {
        $this->logger = $logger;
        $this->security = $security;
    }

    protected function supports($attribute, $subject) {

        // if the attribute isn't one we support, return false
        if (!in_array($attribute, [self::DICTIONARY_OPERATIONS, self::SYNC_UPLOAD, self::SYNC_DOWNLOAD, self::DATA_DOWNLOAD])) {
            return false;
        }

        return true;
    }

    protected function voteOnAttribute($attribute, $subject, TokenInterface $token) {
        $user = $token->getUser();

        $this->logger->debug('dictionary voter voteOnAttribute: ' . print_r($user, true));

        if (!$user instanceof User) {
            // the user must be logged in; if not, deny access
            return false;
        }

        $dictName = $subject;
        switch ($attribute) {
            case self::DICTIONARY_OPERATIONS:
                return $this->canAddOrDeleteDictionaries($user, $attribute);
            case self::DATA_DOWNLOAD:
                return $this->canDownloadData($user, $attribute);
            case self::SYNC_UPLOAD:
                return $this->canSyncUploadData($dictName, $user);
            case self::SYNC_DOWNLOAD:
                return $this->canSyncDownloadData($dictName, $user);
        }

        throw new \LogicException('This code should not be reached!');
    }

    private function canAddOrDeleteDictionaries(User $user, $attribute) {

        $roleName = 'ROLE_' . strtoupper($attribute);
        if ($this->security->isGranted('ROLE_ADMIN') || $this->security->isGranted('ROLE_STANDARD_USER') || $this->security->isGranted($roleName)) {
            return true;
        } else {
            $this->logger->debug('User does not have data_all permissions');
            return false;
        }
    }

    private function canDownloadData(User $user, $attribute) {

        $roleName = 'ROLE_' . strtoupper($attribute);
        if ($this->security->isGranted('ROLE_ADMIN') || $this->security->isGranted('ROLE_STANDARD_USER') || $this->security->isGranted($roleName)) {
            return true;
        } else {
            $this->logger->debug('User does not have data_all permissions');
            return false;
        }
    }

    //built-in standard users and administrators can sync upload data. for other roles check permissions based on the dictionary
    private function canSyncUploadData($dictName, User $user) {
        //if $dictName is empty return false 
        if (empty($dictName))
            return false;

        if ($this->security->isGranted('ROLE_ADMIN') || $user->getRoleId() == User::STANDARD_USER) {//built-in administrator or standard users
            return true;
        } else {
            $role = $user->getUserRole();
            $dictionaryPermissions = new RoleDictionaryPermissions();
            if (isset($role)) {
                $this->logger->debug('dictionary voter: checking permissions sync upload ' . $dictName);
                $dictionaryPermissions = $role->rolePermissions->getDictionaryPermissions($dictName);
                return $dictionaryPermissions->canSyncUpload();
            }
            $this->logger->debug('dictionary voter: denied canSyncUploadData ' . $dictName);
            return false;
        }
    }

    //built-in administrators can download sync spec and standard users cannot. For other users with any other role check permissions based on dictionary name
    private function canSyncDownloadData($dictName, User $user) {
        //if $dictName is empty return false 
        if (empty($dictName))
            return false;

        if ($this->security->isGranted('ROLE_ADMIN') || $user->getRoleId() == User::STANDARD_USER) {//built-in administrator
            return true;
        } else {
            $role = $user->getUserRole();
            $dictionaryPermissions = new RoleDictionaryPermissions();
            if (isset($role)) {
                $this->logger->debug('dictionary voter: checking permissions sync download ' . $dictName);
                $dictionaryPermissions = $role->rolePermissions->getDictionaryPermissions($dictName);
                return $dictionaryPermissions->canSyncDownload();
            }
            $this->logger->debug('dictionary voter: denied canSyncUploadData ' . $dictName);
            return false;
        }
    }

}
