<?php

final class ConduitAPI_differential_getcommitdata_Method
  extends ConduitAPIMethod {

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

    $revision = id(new DifferentialRevision())->load($id);
    if (!$revision) {
      throw new ConduitException('ERR_NOT_FOUND');
    }

    $revision->loadRelationships();

    // Get differential fields
    $aux_fields = DifferentialFieldSelector::newSelector()
      ->getFieldSpecifications();

    // Remove fields that should not appear on commit messages
    foreach ($aux_fields as $key => $aux_field) {
      $aux_field->setRevision($revision);
      if (!$aux_field->shouldAppearOnCommitMessage()) {
        unset($aux_fields[$key]);
      }
    }

    // Get data from fields that uses storage (from getcommitmessage)
    $aux_fields = DifferentialAuxiliaryField::loadFromStorage(
      $revision,
      $aux_fields);

    // Transform array to a hash map
    $aux_fields = mpull($aux_fields, null, 'getCommitMessageKey');

    // Load handles and get data from some fields (from getcommitmessage)
    $aux_phids = array();
    foreach ($aux_fields as $field_key => $field) {
      $aux_phids[$field_key] = $field->getRequiredHandlePHIDsForCommitMessage();
    }
    $handles_phids = array_unique(array_mergev($aux_phids));
    $handles = id(new PhabricatorHandleQuery())
        ->setViewer($request->getUser())
        ->withPHIDs($handles_phids)
        ->execute();
    foreach ($aux_fields as $field_key => $field) {
      $field->setHandles(array_select_keys($handles, $aux_phids[$field_key]));
    }


    // Returned data
    $data = array();

    // Get value for each field
    foreach ($aux_fields as $field_key => $field) {
      $value = $field->renderValueForCommitMessage(false);
      if (strlen($value)) {
        $value = str_replace(array("\r\n", "\r"), array("\n", "\n"), $value);
      }
      $data[$field_key] = $value;
    }

    // Load user objects
    $author_phid = $revision->getAuthorPHID();
    $reviewed_by_phid = $revision->loadReviewedBy();
    $phids = $revision->getReviewers();
    $phids[] = $author_phid;
    $phids[] = $reviewed_by_phid;
    $objects = id(new PhabricatorPeopleQuery())
        ->setViewer($request->getUser())
        ->withPHIDs($phids)
        ->needPrimaryEmail(true)
        ->execute();

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
        $data['author'] = array('name' => $name, 'username' => $username, 'email' => $email);
      } else if ($phid == $reviewed_by_phid) {
        $data['reviewedBy'] = array('name' => $name, 'username' => $username, 'email' => $email);
      } else {
        $data['reviewers'][] = array('name' => $name, 'username' => $username, 'email' => $email);
      }
    }

    return $data;
  }
}
