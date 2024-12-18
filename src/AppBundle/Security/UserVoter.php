<?php

namespace AppBundle\Security;

use AppBundle\CSPro\User\User;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Psr\Log\LoggerInterface;

/**
 * Description of UserVoter
 *
 * @author savy
 */
class UserVoter extends Voter {

    const USERS_ALL = 'users_all';
    const ADD = 'users_add';
    const DELETE = 'users_delete';
    const VIEW = 'users_view';
    const MODIFY = 'users_modify';
    const IMPORT = 'users_import';

    private $logger;

    public function __construct(Security $security, LoggerInterface $logger) {
        $this->logger = $logger;
        $this->security = $security;
    }

    protected function supports($attribute, $subject) {

        // if the attribute isn't one we support, return false
        if (!in_array($attribute, [self::USERS_ALL])) {
            return false;
        }

        return true;
    }

    protected function voteOnAttribute($attribute, $subject, TokenInterface $token) {
        $user = $token->getUser();
        $this->logger->debug('user voter voteOnAttribute: ' . print_r($user, true));

        if (!$user instanceof User) {
            // the user must be logged in; if not, deny access
            return false;
        }
        // id has user 
        switch ($attribute) {
            case self::USERS_ALL:
                return $this->hasUserRole($user, $attribute);
        }
        throw new \LogicException('This code should not be reached!');
    }

    //built-in administrators can add  and standard users cannot. For other users with any other role check permissions
    private function hasUserRole(User $user, $attribute) {
        $roleName = 'ROLE_' . strtoupper($attribute);
        if ($this->security->isGranted('ROLE_ADMIN') || $this->security->isGranted($roleName)) {
            return true;
        } else {
            $this->logger->debug('User does not have users_all permissions');
            return false;
        }
    }

}
