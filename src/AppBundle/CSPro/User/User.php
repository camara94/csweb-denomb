<?php

namespace AppBundle\CSPro\User;

use AppBundle\CSPro\User\Role;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 
 * User class
 * 
 */
class User implements UserInterface {

    private $userName;
    private $password;
    private $firstName;
    private $lastName;
    private $roles; //symfony dictionary voter roles 'ROLE_..'
    private $roleId;
    private $email;
    private $phone;
    private $userRole;

    const STANDARD_USER = 1;
    const ADMINISTRATOR = 2;

    function __construct($username, $firstname, $lastname, $roleId, $password, $email = null, $phone = null) {
        $this->userName = $username;
        $this->firstName = $firstname;
        $this->lastName = $lastname;
        $this->roleId = $roleId;
        $this->password = $password;
        $this->email = $email;
        $this->phone = $phone;
        $this->roles = array();
    }

    public function setAllMembers($username, $firstname, $lastname, $roleId, $password, $email = null, $phone = null) {

        $this->userName = $username;
        $this->firstName = $firstname;
        $this->lastName = $lastname;
        $this->roleId = $roleId;
        $this->password = $password;
        $this->email = $email;
        $this->phone = $phone;
    }

    public function getUsername() {
        return $this->userName;
    }

    public function setUserName($userName) {
        $this->userName = $userName;
    }

    public function getPassword() {
        return $this->password;
    }

    public function setPassword($password) {
        $this->password = $password;
    }

    public function getFirstName() {
        return $this->firstName;
    }

    public function setFirstName($firstName) {
        $this->firstName = $firstName;
    }

    public function getLastName() {
        return $this->lastName;
    }

    public function setLastName($lastName) {
        $this->lastName = $lastName;
    }

    public function getEmail() {
        return $this->email;
    }

    public function setEmail($email) {
        $this->email = $email;
    }

    public function getPhone() {
        return $this->phone;
    }

    public function setPhone($phone) {
        $this->phone = $phone;
    }

    //Symfony UserInterface - getRoles is called to determine the builtin roles assigned to user
    public function getRoles() {
        return $this->roles;
    }

    public function setRoles($roles) {
        $this->roles = $roles;
    }

    public function getUserRole() {
        return $this->userRole;
    }

    public function setUserRole(Role $role) {
        $this->userRole = $role;
    }

    public function getSalt() {
        return null;
    }

    public function eraseCredentials() {
        
    }

    public function getRoleId() {
        return $this->roleId;
    }

    public function setRoleId($roleId) {
        $this->roleId = $roleId;
    }

    public function toString() {
        $username2 = $this->userName != null ? $this->userName : "NA";
        $firstname2 = $this->firstName != null ? $this->firstName : "NA";
        $lastname2 = $this->lastName != null ? $this->lastName : "NA";
        $role2 = $this->roleId != null ? $this->roleId : "NA";
        $password2 = $this->password != null ? $this->password : "NA";

        return "username:" . $username2 . "  firstname:" . $firstname2 . "  lastname:" . $lastname2 . "  role:" . $role2 . "  password:" . $password2;
    }

}
