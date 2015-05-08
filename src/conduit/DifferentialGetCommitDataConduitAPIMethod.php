<?php

class DifferentialGetCommitDataConduitAPIMethod
  extends ConduitAPIMethod {

  public function getAPIMethodName() {
    return 'differential.getcommitdata';
  }

  public function getMethodDescription() {
    return "Retrieve Differential data to use on a custom commit message";
  }

  public function defineParamTypes() {
    return array(
      'revision_id' => 'required revision_id',
    );
  }

  public function defineReturnType() {
    return 'nonempty dict';
  }

  public function defineErrorTypes() {
    return array(
      'ERR_NOT_FOUND' => 'Revision was not found.',
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $id = $request->getValue('revision_id');
    $viewer = $request->getUser();

    $revision = id(new DifferentialRevisionQuery())
      ->withIDs(array($id))
      ->setViewer($viewer)
      ->needRelationships(true)
      ->needReviewerStatus(true)
      ->executeOne();
    if (!$revision) {
      throw new ConduitException('ERR_NOT_FOUND');
    }

    // Get fields for DifferentialRevision
    $field_list = PhabricatorCustomField::getObjectFields($revision,
      DifferentialCustomField::ROLE_COMMITMESSAGE);

    // Populate fields with revision data
    $field_list
      ->setViewer($viewer)
      ->readFieldsFromStorage($revision);

    // Transform array to a hash map
    $field_map = mpull($field_list->getFields(), null, 'getFieldKeyForConduit');

    // Load handles and get data from some fields (from getcommitmessage)
    $phids = array();
    foreach ($field_list->getFields() as $key => $field) {
      $field_phids = $field->getRequiredHandlePHIDsForCommitMessage();
      // I don't know how to handle this error. This code was copied from getcommitmessage
      if (!is_array($field_phids)) {
        throw new Exception(
          pht(
            'An error occured. Something may have changed in the API. '.
            'See getcommitmessage for more'));
      }
      $phids[$key] = $field_phids;
    }
    $all_phids = array_mergev($phids);
    if ($all_phids) {
      $all_handles = id(new PhabricatorHandleQuery())
        ->setViewer($viewer)
        ->withPHIDs($all_phids)
        ->execute();
    } else {
      $all_handles = array();
    }

    // Get value for each field
    $raw_data = array();
    foreach ($field_list->getFields() as $field_key => $field) {
      $handles = array_select_keys($all_handles, $phids[$field_key]);
      $value = $field->renderCommitMessageValue($handles);
      if (strlen($value)) {
        $value = str_replace(array("\r\n", "\r"), array("\n", "\n"), $value);
      }
      $raw_data[$field_key] = $value;
    }

    // Load user objects
    $author_phid = $revision->getAuthorPHID();
    $reviewed_by_phids = array();
    foreach ($revision->getReviewerStatus() as $rev_status) {
      if ($rev_status->getStatus() === DifferentialReviewerStatus::STATUS_ACCEPTED ||
        $rev_status->getStatus() === DifferentialReviewerStatus::STATUS_ACCEPTED_OLDER) {

        $reviewed_by_phids[] = $rev_status->getReviewerPHID();
      }
    }
    $phids = $revision->getReviewers();
    $phids[] = $author_phid;
    $objects = id(new PhabricatorPeopleQuery())
        ->setViewer($viewer)
        ->withPHIDs($phids)
        ->needPrimaryEmail(true)
        ->execute();

    $author_emails = id(new PhabricatorUserEmail())->loadAllWhere(
        'userPHID = %s',
        $author_phid);
    $author_emails = mpull($author_emails, 'getAddress');

    $reviewers_emails = [];
    foreach ($revision->getReviewers() as $reviewer_phid) {
      $reviewers_emails[$reviewer_phid] = id(new PhabricatorUserEmail())->loadAllWhere(
          'userPHID = %s',
          $reviewer_phid);
      $reviewers_emails[$reviewer_phid] = mpull($reviewers_emails[$reviewer_phid], 'getAddress');
    }
    // Returned data
    $data = array();

    // Remove : from keys and rename revision-id to revisionID
    foreach ($raw_data as $key => $value) {
      $pos = strpos($key, ':');
      if ($pos !== false) {
        $new_key = substr($key, $pos+1);
      } else {
        $new_key = $key;
      }

      if ($new_key == 'revision-id') {
        $new_key = 'revisionID';
      }

      $data[$new_key] = $value;
    }

    // For user's fields, get name, username and e-mail
    $data['author'] = null;
    $data['reviewedBy'] = null;
    $data['reviewers'] = array();
    foreach ($objects as $user) {
      $phid = $user->getPHID();
      $name = $user->getRealName();
      $username = $user->getUserName();
      $email = $user->loadPrimaryEmail();
      if ($email)
        $email = $email->getAddress();

      if ($phid == $author_phid) {
        $data['author'] = array('name' => $name, 'username' => $username, 'email' => $email, 'emails' => $author_emails);
      } else if (in_array($phid, $reviewed_by_phids)) {
        $reviewedBy = array('name' => $name, 'username' => $username, 'email' => $email, 'emails' => $reviewers_emails[$phid]);
        $data['reviewedBy'] = $reviewedBy; // Legacy
        $data['accept_reviewers'][] = $reviewedBy;
      } else {
        $data['reviewers'][] = array('name' => $name, 'username' => $username, 'email' => $email, 'emails' => $reviewers_emails[$phid]);
      }
    }

    return $data;
  }
}
