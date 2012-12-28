<?php

class RegisterController extends PhabricatorController {
  public function shouldRequireEnabledUser() {
    return false;
  }

  public function shouldAllowPublic() {
    return true;
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = new PhabricatorUser();

    if ($request->getUser()->getPHID()) {
      $response = id(new AphrontRedirectResponse())
        ->setURI('/p/'.$request->getUser()->getUserName());
      return $response;
    }

    $e_username = false;
    $e_realname = false;
    $e_email = false;
    $errors = array();

    $email = null;

    if ($request->isFormPost()) {
      $user->setUsername($request->getStr('username'));
      $user->setRealName($request->getStr('realname'));
      $email = $request->getStr('email');

      // Verify e-mail
      if (!strlen($email)) {
        $errors[] = 'Email is required.';
        $e_email = 'Required';
      } else if (!PhabricatorUserEmail::isAllowedAddress($email)) {
        $e_email = 'Invalid';
        $errors[] = PhabricatorUserEmail::describeAllowedAddresses();
      } else {
        $e_email = null;
      }

      // Verify username
      if (!strlen($user->getUsername())) {
        $errors[] = "Username is required.";
        $e_username = 'Required';
      } else if (!PhabricatorUser::validateUsername($user->getUsername())) {
        $errors[] = PhabricatorUser::describeValidUsername();
        $e_username = 'Invalid';
      } else {
        $e_username = null;
      }

      // Verify real name
      if (!strlen($user->getRealName())) {
        $errors[] = 'Real name is required.';
        $e_realname = 'Required';
      } else {
        $e_realname = null;
      }

      if (!$errors) {
        try {

          $user_email = id(new PhabricatorUserEmail())
            ->setAddress($email)
            ->setIsVerified(0);

          $admin = $user;
          id(new PhabricatorUserEditor())
            ->setActor($admin)
            ->createNewUser($user, $user_email);

          $user->sendWelcomeEmail($admin);

          $response = id(new AphrontDialogResponse())
            ->setDialog(
              id(new AphrontDialogView())
                ->setUser($user)
                ->setTitle('Registration complete')
                ->appendChild("<p>Please check your e-mail for further instructions</p>"));

          return $response;
        } catch (AphrontQueryDuplicateKeyException $ex) {
          $errors[] = 'Username and email must be unique.';

          $same_username = id(new PhabricatorUser())
            ->loadOneWhere('username = %s', $user->getUsername());
          $same_email = id(new PhabricatorUserEmail())
            ->loadOneWhere('address = %s', $email);

          if ($same_username) {
            $e_username = 'Duplicate';
          }

          if ($same_email) {
            $e_email = 'Duplicate';
          }
        }
      }
    }

    $error_view = null;
    if ($errors) {
      $error_view = id(new AphrontErrorView())
        ->setTitle('Form Errors')
        ->setErrors($errors);
    }

    $form = id(new AphrontFormView())
      ->setAction($request->getRequestURI()->getPath())
      ->setUser($user)
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel('Username')
          ->setName('username')
          ->setValue($user->getUsername())
          ->setError($e_username))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel('Real Name')
          ->setName('realname')
          ->setValue($user->getRealName())
          ->setError($e_realname))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel('Email')
          ->setName('email')
          ->setValue($email)
          ->setCaption(PhabricatorUserEmail::describeAllowedAddresses())
          ->setError($e_email))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue('Register'));

    $header = id(new PhabricatorHeaderView())
      ->setHeader('User registration');

    return $this->buildApplicationPage(array($header, $error_view, $form), array('title' => 'User registration'));
  }
}
