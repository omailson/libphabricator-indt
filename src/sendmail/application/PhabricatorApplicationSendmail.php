<?php

/**
 * Sends an e-mail to every register user on phabricator or to all members of 
 * an specific project
 */
final class PhabricatorApplicationSendmail extends PhabricatorApplication {
  public function shouldAppearInLaunchView() {
    return true;
  }

  public function getBaseURI() {
    return '/sendmail/';
  }

  public function getShortDescription() {
    return 'Send an e-mail to all users';
  }

  public function getIconName() {
    return 'mail';
  }

  public function getTitleGlyph() {
    return "@";
  }

  public function getFlavorText() {
    return pht('With great power comes great responsibility');
  }

  public function getApplicationGroup() {
    return self::GROUP_ADMIN;
  }

  public function getRoutes() {
    return array(
      '/sendmail/' => array(
        '' => 'SendmailController',
      ),
    );
  }
}
