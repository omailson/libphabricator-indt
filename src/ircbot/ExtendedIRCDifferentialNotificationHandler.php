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

      $actor_name = $actor_handle[$actor_phid]->getName();
      $message = "revision D".$data['revision_id']." updated [".self::getAction($data['action'])."] - ".PhabricatorEnv::getEnvConfig('phabricator.base-uri')."D".$data['revision_id'];
      if (!empty($names)) {
        $highlight = implode(", ", $names);
        $message = $highlight.": ".$message;
      }

      foreach ($this->getConfig('notification.channels') as $channel) {
        $this->write('PRIVMSG', "{$channel} :{$message}");
      }
    }
  }

  private static function getAction($action) {
    $actions = array(
      DifferentialAction::ACTION_COMMENT        => 'comment',
      DifferentialAction::ACTION_ACCEPT         => 'accepted',
      DifferentialAction::ACTION_REJECT         => 'request changes',
      DifferentialAction::ACTION_RETHINK        => 'plan changes',
      DifferentialAction::ACTION_ABANDON        => 'abandoned',
      DifferentialAction::ACTION_CLOSE          => 'closed',
      DifferentialAction::ACTION_REQUEST        => 'request review',
      DifferentialAction::ACTION_RECLAIM        => 'reclaimed',
      DifferentialAction::ACTION_UPDATE         => 'updated',
      DifferentialAction::ACTION_RESIGN         => 'reviewer--',
      DifferentialAction::ACTION_SUMMARIZE      => 'summarized',
      DifferentialAction::ACTION_TESTPLAN       => 'test plan',
      DifferentialAction::ACTION_CREATE         => 'created',
      DifferentialAction::ACTION_ADDREVIEWERS   => 'reviewer++',
      DifferentialAction::ACTION_ADDCCS         => 'add CC',
      DifferentialAction::ACTION_CLAIM          => 'claim',
    );

    return $actions[$action];
  }

}
