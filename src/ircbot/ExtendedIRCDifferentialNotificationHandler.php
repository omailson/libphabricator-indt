<?php
/**
 * Notify on IRC channel the people involved in the revision.
 *
 * This irc bot tracks changes made to revisions and sends a message to the
 * channel when a new change occurs.
 * The message notifies each reviewer and the author of the review, unless 
 * that person was the one who performed the change. 
 * There's no need to notify who did the change.
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
    $show = $this->getConfig('notification.actions');

    if (!$this->skippedOldEvents) {
      foreach ($iterator as $event) {
        // Ignore all old events.
      }
      $this->skippedOldEvents = true;
      return;
    }

    foreach ($iterator as $event) {
      $data = $event->getData();
      if (!$data || ($show !== null && !in_array($data['action'], $show))) {
        continue;
      }

      $actor_phid = $data['actor_phid'];
      $revision_phid = $data['revision_phid'];

      $actor_handle = id(new PhabricatorObjectHandleData(array($actor_phid)))->loadHandles();

      $objects = id(new PhabricatorObjectHandleData(array($revision_phid)))->loadObjects();
      $revision = $objects[$revision_phid];
      $revision->loadRelationships();

      // Add reviewers
      $phids = $revision->getReviewers();
      // Add revision author
      $phids[] = $data['revision_author_phid'];
      // Remove comment author
      $phids = array_diff($phids, array($actor_phid));

      $handles = id(new PhabricatorObjectHandleData($phids))->loadHandles();
      foreach ($handles as $key => $handle) {
        $names[] = $handle->getName();
      }

      // Set message and recipients
      if ($data['action'] == DifferentialAction::ACTION_CREATE) {
        $message = "check out this new revison: ".$this->printRevision($data['revision_id']);
        $recipients = $this->getConfig('notification.channels');

        if (!empty($names)) {
          $highlight = implode(", ", $names);
          $message = $highlight.": ".$message;
        }
      } else {
        $actor_name = $actor_handle[$actor_phid]->getName();
        $verb = DifferentialAction::getActionPastTenseVerb($data['action']);
        $message = "${actor_name} ${verb} revision ".$this->printRevision($data['revision_id']);

        // We already have the message. Let's see who wants to read that.
        switch ($data['action']) {
        case DifferentialAction::ACTION_COMMENT:
        case DifferentialAction::ACTION_UPDATE:
          // EVERYONE!
          $recipients = $names;
          break;
        case DifferentialAction::ACTION_ACCEPT:
        case DifferentialAction::ACTION_REJECT:
        case DifferentialAction::ACTION_RETHINK:
          // Only to the author of the revision
          $author = $handles[$data['revision_author_phid']];
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
