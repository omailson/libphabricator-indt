<?php

class SendmailController extends PhabricatorController {
  public function shouldRequireAdmin() {
    return true;
  }

  public function processRequest() {
    $request = $this->getRequest();
    $loggedUser = $request->getUser();

    if ($request->isFormPost()) {
      $subject = $request->getStr('subject');
      $body = $request->getStr('body');
      $tos = $request->getArr('to');
      $member_phids = array();
      if ($tos) {
        $query = id(new PhabricatorProjectQuery())
          ->setViewer($loggedUser)
          ->withPHIDs($tos)
          ->needMembers(true);
        $projects = $query->execute();
        $member_phids = mpull($projects, 'getMemberPHIDs');
        $member_phids = array_mergev($member_phids);
      }

      $users = id(new PhabricatorPeopleQuery())
        ->needPrimaryEmail(true)
        ->withPHIDs($member_phids) // means everyone, if this array is empty
        ->execute();

      $recipients = array();
      $userNames = array();
      foreach ($users as $user) {
        $primary_email = $user->loadPrimaryEmail();
        if ($primary_email && $primary_email->getIsVerified() && !$user->getIsSystemAgent()) {
          $recipients[] = $user->getPHID();
          $userNames[] = $user->getUserName();
        }
      }

      $mail = new PhabricatorMetaMTAMail();
      $mail->addTos($recipients);
      $mail->setSubject($subject);
      $mail->setBody($body);
      $mail->setFrom($request->getUser()->getPHID());
      // $mail->save(); TODO

      $dialog = id(new AphrontDialogView())
        ->setUser($loggedUser)
        ->setTitle('E-mail sent')
        ->appendChild('<p><strong>Recipients:</strong> '.implode(',', $userNames).'</p>')
        ->appendChild('<p><strong>Subject:</strong> '.$subject.'</p>')
        ->appendChild('<p>'.$body.'</p>');
      return id(new AphrontDialogResponse())->setDialog($dialog);
    }

    $form = id(new AphrontFormView())
      ->setUser($loggedUser)
      ->setAction($request->getRequestURI()->getPath())
      ->appendChild(
        id(new AphrontFormTokenizerControl())
          ->setPlaceholder('Type a project name or leave it blank to send it to everyone...')
          ->setLabel('To')
          ->setName('to')
          ->setDatasource('/typeahead/common/projects/'))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel('Subject')
          ->setName('subject'))
      ->appendChild(
        id(new AphrontFormTextAreaControl())
          ->setLabel('Body')
          ->setName('body'))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue('Send Mail'));

    $header = id(new PhabricatorHeaderView())
      ->setHeader('Send Mail');

    return $this->buildApplicationPage(array($header, $form), array('title' => 'Send Mail'));
  }
}
