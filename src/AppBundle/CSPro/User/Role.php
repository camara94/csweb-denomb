<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace AppBundle\CSPro\User;

/**
 * Description of Role
 *
 * @author savy
 */
class RoleDictionaryPermissions
{
   public $dictionaryname;
   public $dictionaryId; 
   public $syncUpload;
   public $syncDownload;

   public function __construct($dictName='', $dictId='', $syncUpload =false, $syncDownload=false){
         $this->dictionaryname = $dictName;
         $this->dictionaryId = $dictId;
         $this->syncUpload =  $syncUpload;         
         $this->syncDownload =  $syncDownload;
    }
    public function canSyncUpload () { return $this->syncUpload; }
    public function canSyncDownload () {return $this->syncDownload;}
    public function setSyncDownloadPermission ($flag) { $this->syncDownload = $flag;}
    public function setSyncUploadPermission ($flag) { $this->syncUpload = $flag ;}
}
class RolePermissions
{
    const DATA_ALL =1;
    const APPS_ALL =2;
    const USERS_ALL =3;
    const ROLES_ALL = 4;
    const REPORTS_ALL = 5;
    const DICTIONARY_SYNC_UPLOAD = 6;
    const DICTIONARY_SYNC_DOWNLOAD  = 7;
    const SETTINGS_ALL  = 8;
    
    public $permissions;
    public $dictionaryPermissions;
    public function __construct(){
         $this->permissions =  array();
         $this->dictionaryPermissions =  array();
    }
    
    public function setPermission($permissionType, $flag){
        $this->permissions[$permissionType] = $flag;
    }
    public function getPermission($permissionType){
       if (isset($this->permissions[$permissionType])){
            return $this->permissions[$permissionType];
       }
       else {
            return false;
       }
    }
    public function setDictionaryPermission(RoleDictionaryPermissions $dictPermission){
       if (isset($dictPermission)){
            $this->dictionaryPermissions[$dictPermission->dictionaryname] = $dictPermission;
       }
    }
    public function getDictionaryPermissions($dictName){
          if(array_key_exists($dictName,$this->dictionaryPermissions)) {
            return $this->dictionaryPermissions[$dictName];
          }
          return null;
    }

};
    
class Role {
    public $name;
    public $id;
    public $rolePermissions;
    public function __construct(){
        $this->rolePermissions = new RolePermissions();
    }
    public function getName() { return $this->name;}    
    public function getId() { return $this->id;}
    public function setName($name) {  $this->name = $name;}    
    public function setId($id) {  $this->id = $id;}
}
