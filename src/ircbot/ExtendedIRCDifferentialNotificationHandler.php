<?php
/**
 * Notify on IRC the people involved in the revision.
 *
 * This irc bot tracks changes made to revisions and notifies each reviewer and
 * the author of the review, unless that person was the one who performed the
 * change.
 *
 * @group irc
 */
final class ExtendedIRCDifferentialNotificationHandler
  extends PhabricatorIRCHandler {

  private $skippedOldEvents;

  public function receiveMessage(PhabricatorIRCMessage $message) {
    return;
  }

  public function runBackgroundTasks() {
    $iterator = new PhabricatorTimelineIterator('ircdiffx', array('difx'));

    if (!$this->skippedOldEvents) {
      foreach ($iterator as $event) {
        // Ignore all old events.
      }
      $this->skippedOldEvents = true;
      return;
    }

    foreach ($iterator as $event) {
      $data = $event->getData();
      if (!$data) {
        continue;
      }

      $revision_phid = $data['revision_phid'];
      $actor_phid = $data['actor_phid'];
      $author_phid = $data['revision_author_phid'];

      // Load revision
      $objects = id(new PhabricatorObjectHandleData(array($revision_phid)))->loadObjects();
      $revision = $objects[$revision_phid];
      $revision->loadRelationships();

      // Load object handles
      $phids = $revision->getReviewers();
      $phids = array_merge($phids, array($actor_phid, $author_phid));
      $phids[] = $revision->getArcanistProjectPHID();
      $handles = id(new PhabricatorObjectHandleData($phids))->loadHandles();

      // Users to notify
      foreach ($handles as $phid => $handle) {
        if ($handle->getType() == PhabricatorPHIDConstants::PHID_TYPE_USER && $phid != $actor_phid)
          $usernames[] = $handle->getName();
      }

      // Set message and recipients
      $message = "";
      $recipients = array();
      $project_name = $handles[$revision->getArcanistProjectPHID()]->getName();
      if ($data['action'] == DifferentialAction::ACTION_CREATE) {
        // chr(2) -> bold text
        $message = chr(2)."[{$project_name}]".chr(2)." new revision: ".$this->printRevision($data['revision_id']);

        // Channel that receives notifications from this project
        $projects = $this->getConfig('notification.projects');
        if (isset($projects[$project_name]))
            $recipients = array($projects[$project_name]);

        if (!empty($usernames)) {
          $highlight = implode(", ", $usernames);
          $message = "{$message} ({$highlight})";
        }
      } else {
        $actor_name = $handles[$actor_phid]->getName();
        $author_name = $handles[$author_phid]->getName();
        $verb = DifferentialAction::getActionPastTenseVerb($data['action']);
        $message = chr(2)."[{$project_name}]".chr(2)." ${actor_name} ${verb} revision ".$this->printRevision($data['revision_id'])." ($author_name)";

        // We already have the message. Let's see who wants to read that.
        switch ($data['action']) {
        case DifferentialAction::ACTION_COMMENT:
        case DifferentialAction::ACTION_UPDATE:
        case DifferentialAction::ACTION_RETHINK:
          // EVERYONE!
          $recipients = $usernames;
          break;
        case DifferentialAction::ACTION_ACCEPT:
        case DifferentialAction::ACTION_REJECT:
          // Only to the author of the revision
          $author = $handles[$author_phid];
          $recipients = array($author->getName());
          break;
        default:
          // Don't send message to anyone
          $recipients = array();
          break;
        }
      }

      foreach ($recipients as $recipient) {
        $this->write('PRIVMSG', "{$recipient} :{$message}");
      }
    }
  }

  private function printRevision($revision_id) {
    $revisions = $this->getConduit()->callMethodSynchronous(
      'differential.query',
      array(
        'query' => 'revision-ids',
        'ids'   => array($revision_id),
      ));
    $revision = $revisions[0];
    return "D".$revision_id.": ".$revision['title']." - ".$revision['uri'];
  }
}
