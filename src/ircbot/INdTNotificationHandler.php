<?php
class INdTNotificationHandler
  extends ExtendedIRCDifferentialNotificationHandler {

  public function processMessage(DifferentialRevision $revision, $data) {
    $project = $data['project'];

    $message = "";
    $project_name = $project->getName();
    if ($data['action'] === DifferentialAction::ACTION_CREATE) {
      // chr(2) -> bold text
      $message = chr(2)."[{$project_name}]".chr(2)." new revision: ".$this->printRevision($data['revision_id']);

      $usernames = array();
      $channels = array();
      foreach ($data['reviewers'] as $reviewer) {
        $usernames[] = $reviewer->getName();
      }

      if (!empty($usernames)) {
        $highlight = implode(", ", $usernames);
        $message = "{$message} ({$highlight})";
      }
    } else {
      $actor_name = $data['actor']->getName();
      $author_name = $data['author']->getName();
      $verb = $this->getActionPastTenseVerb($data['action']);
      $message = chr(2)."[{$project_name}]".chr(2)." ${actor_name} ${verb} revision ".$this->printRevision($data['revision_id'])." ($author_name)";
    }

    return $message;
  }

  public function processRecipients(DifferentialRevision $revision, $data) {
    $targets = array();
    switch ($data['action']) {
    case DifferentialAction::ACTION_CREATE:
      // Channel that receives notifications from this project
      $projects = $this->getConfig('notification.projects');
      $project_name = $data['project']->getName();
      if (isset($projects[$project_name])) {
        $targets[] = id(new PhabricatorBotChannel())
          ->setName($projects[$project_name]);
      }
      break;
    case DifferentialAction::ACTION_COMMENT:
    case DifferentialAction::ACTION_UPDATE:
    case DifferentialAction::ACTION_RETHINK:
      $actor_phid = $data['actor_phid'];
      $author_phid = $data['revision_author_phid'];

      foreach ($data['reviewers'] as $reviewer) {
        if ($actor_phid !== $reviewer->getPHID())
          $targets[] = id(new PhabricatorBotUser())
            ->setName($reviewer->getName());
      }

      if ($author_phid !== $actor_phid) {
        $targets[] = id(new PhabricatorBotUser())
          ->setName($data['author']->getName());
      }
      break;
    case DifferentialAction::ACTION_ACCEPT:
    case DifferentialAction::ACTION_REJECT:
      $targets[] = id(new PhabricatorBotUser())
        ->setName($data['author']->getName());
      break;
    default:
      break;
    }

    return $targets;
  }
}
