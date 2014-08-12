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
abstract class ExtendedIRCDifferentialNotificationHandler
  extends PhabricatorBotHandler {

  private $startupDelay = 30;
  private $lastSeenChronoKey = 0;

  /**
   * Given enought information about the revision, returns a list of
   * PhabricatorBotTarget who the message will be delivered to
   */
  abstract protected function processRecipients(DifferentialRevision $revision, $data);

  /**
   * Given enough information about the revision, return the message to send to the recipients
   */
  abstract protected function processMessage(DifferentialRevision $revision, $data);

  public function receiveMessage(PhabricatorBotMessage $message) {
    return;
  }

  private function shouldShowStory($story) {
    $story_objectphid = $story['objectPHID'];
    $obj_type = phid_get_type($story_objectphid);

    if ($obj_type === 'DREV')
      return true;

    return false;
  }

  public function runBackgroundTasks() {
    if ($this->startupDelay > 0) {
        // the event loop runs every 1s so delay enough to fully conenct
        $this->startupDelay--;

        return;
    }
    if ($this->lastSeenChronoKey == 0) {
      // Since we only want to post notifications about new stories, skip
      // everything that's happened in the past when we start up so we'll
      // only process real-time stories.
      $latest = $this->getConduit()->callMethodSynchronous(
        'feed.query',
        array(
          'limit'=>1
        ));

      foreach ($latest as $story) {
        if ($story['chronologicalKey'] > $this->lastSeenChronoKey) {
          $this->lastSeenChronoKey = $story['chronologicalKey'];
        }
      }

      return;
    }

    $config_max_pages = 5;
    $config_page_size = 10;

    $last_seen_chrono_key = $this->lastSeenChronoKey;
    $chrono_key_cursor = 0;

    // Not efficient but works due to feed.query API
    for ($max_pages = $config_max_pages; $max_pages > 0; $max_pages--) {
      $stories = $this->getConduit()->callMethodSynchronous(
        'feed.query',
        array(
          'limit'=>$config_page_size,
          'after'=>$chrono_key_cursor,
          'view'=>'text'
        ));

      foreach ($stories as $story) {
        if ($story['chronologicalKey'] == $last_seen_chrono_key) {
          // Caught up on feed
          return;
        }
        if ($story['chronologicalKey'] > $this->lastSeenChronoKey) {
          // Keep track of newest seen story
          $this->lastSeenChronoKey = $story['chronologicalKey'];
        }
        if (!$chrono_key_cursor ||
            $story['chronologicalKey'] < $chrono_key_cursor) {
          // Keep track of oldest story on this page
          $chrono_key_cursor = $story['chronologicalKey'];
        }

        if (!$story['text'] ||
            !$this->shouldShowStory($story)) {
          continue;
        }

        $revision_phid = $story['objectPHID'];
        $actor_phid = $story['authorPHID'];

        $conduit_user = id(new PhabricatorUser())
            ->loadOneWhere('username = %s', $this->getConfig('conduit.user'));

        // Load revision
        $revision = id(new DifferentialRevisionQuery())
            ->setViewer($conduit_user)
            ->withPHIDs(array($revision_phid))
            ->needRelationships(true)
            ->executeOne();

        // Load object handles
        $author_phid = $revision->getAuthorPHID();
        $phids = $revision->getReviewers();
        $phids = array_merge($phids, array($actor_phid, $author_phid));
        $phids[] = $revision->getArcanistProjectPHID();
        $handles = id(new PhabricatorHandleQuery())
            ->setViewer($conduit_user)
            ->withPHIDs($phids)
            ->execute();

        $data = array();
        $data['actor'] = $handles[$actor_phid];
        $data['actor_phid'] = $actor_phid;
        $data['author'] = $handles[$author_phid];
        $data['revision_author_phid'] = $author_phid;
        $data['project'] = $handles[$revision->getArcanistProjectPHID()];
        $data['action'] = $this->parseAction($story);
        $data['revision_id'] = $revision->getId();
        $data['reviewers'] = array();
        foreach ($revision->getReviewers() as $phid) {
          $data['reviewers'][] = $handles[$phid];
        }

        // Users to notify
        $targets = $this->processRecipients($revision, $data);
        // Message to send
        $message = $this->processMessage($revision, $data);

        $messages = array();
        foreach ($targets as $target) {
          $messages[] = id(new PhabricatorBotMessage())
            ->setCommand('MESSAGE')
            ->setTarget($target)
            ->setBody($message);
        }

        // Send messages
        foreach ($messages as $msg) {
          $this->writeMessage($msg);
        }
      }
    }
  }

  protected function getActionPastTenseVerb($action) {
    $verbs = array(
      DifferentialAction::ACTION_COMMENT        => 'commented on',
      DifferentialAction::ACTION_ACCEPT         => 'accepted',
      DifferentialAction::ACTION_REJECT         => 'requested changes to',
      DifferentialAction::ACTION_RETHINK        => 'planned changes to',
      DifferentialAction::ACTION_ABANDON        => 'abandoned',
      DifferentialAction::ACTION_CLOSE          => 'closed',
      DifferentialAction::ACTION_REQUEST        => 'requested a review of',
      DifferentialAction::ACTION_RECLAIM        => 'reclaimed',
      DifferentialAction::ACTION_UPDATE         => 'updated',
      DifferentialAction::ACTION_RESIGN         => 'resigned from',
      DifferentialAction::ACTION_SUMMARIZE      => 'summarized',
      DifferentialAction::ACTION_TESTPLAN       => 'explained the test plan for',
      DifferentialAction::ACTION_CREATE         => 'created',
      DifferentialAction::ACTION_ADDREVIEWERS   => 'added reviewers to',
      DifferentialAction::ACTION_ADDCCS         => 'added CCs to',
      DifferentialAction::ACTION_CLAIM          => 'commandeered',
      DifferentialAction::ACTION_REOPEN         => 'reopened',
      DifferentialTransaction::TYPE_INLINE      => 'commented on',
    );

    if (!empty($verbs[$action])) {
      return $verbs[$action];
    } else {
      return 'brazenly "'.$action.'ed"';
    }
  }

  private function parseAction($story) {
    // See (in that order):
    // DifferentialTransaction::getTitleForFeed()
    // PhabricatorApplicationTransaction::getTitleForFeed()
    // PhabricatorApplicationTransaction::getTitle()
    $story_text = $story['text'];
    $patterns = array(
      DifferentialAction::ACTION_COMMENT        => '/^\w+ added a comment to/',
      DifferentialAction::ACTION_ACCEPT         => '/^\w+ accepted/',
      DifferentialAction::ACTION_REJECT         => '/^\w+ requested changes to/',
      DifferentialAction::ACTION_RETHINK        => '/^\w+ planned changes to/',
      DifferentialAction::ACTION_ABANDON        => '/^\w+ abandoned/',
      DifferentialAction::ACTION_CLOSE          => '/^\w+ closed/',
      DifferentialAction::ACTION_REQUEST        => '/^\w+ requested review of/',
      DifferentialAction::ACTION_RECLAIM        => '/^\w+ reclaimed/',

      DifferentialAction::ACTION_RESIGN         => '/^\w+ resigned from/',
      DifferentialAction::ACTION_SUMMARIZE      => '/^\w+ summarized/', // XXX
      DifferentialAction::ACTION_TESTPLAN       => '/^\w+ updated the test plan for/',
      DifferentialAction::ACTION_CREATE         => '/^\w+ created/',
      DifferentialAction::ACTION_ADDREVIEWERS   => '/^\w+ updated reviewers of/',
      DifferentialAction::ACTION_ADDCCS         => '/^\w+ updated subscribers of/',

      DifferentialAction::ACTION_UPDATE         => '/^\w+ updated/', // Put after all other updates

      DifferentialAction::ACTION_CLAIM          => '/^\w+ commandeered/',
      DifferentialAction::ACTION_REOPEN         => '/^\w+ reopened/', // XXX
      DifferentialTransaction::TYPE_INLINE      => '/^\w+ commented on/', // XXX
    );

    foreach ($patterns as $key => $pattern) {
      if (preg_match($pattern, $story_text))
        return $key;
    }
  }

  protected function printRevision($revision_id) {
    $revisions = $this->getConduit()->callMethodSynchronous(
      'differential.query',
      array(
        'ids'   => array($revision_id),
      ));
    $revision = $revisions[0];
    return "D".$revision_id.": ".$revision['title']." - ".$revision['uri'];
  }
}
