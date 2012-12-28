<?php

/**
 * Provide a way to new users register themselves
 */
final class PhabricatorApplicationRegister extends PhabricatorApplication {
  public function shouldAppearInLaunchView() {
    return false;
  }

  public function getBaseURI() {
    return '/register/';
  }

  public function getShortDescription() {
    return 'Register a new user';
  }

  public function getApplicationGroup() {
    return self::GROUP_MISC;
  }

  public function getRoutes() {
    return array(
      '/register/' => array(
        '' => 'RegisterController',
      ),
    );
  }
}
