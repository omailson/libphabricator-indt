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
  extends PhabricatorBotHandler {

  private $startupDelay = 30;
  private $lastSeenChronoKey = 0;
  // private $skippedOldEvents;

  public function receiveMessage(PhabricatorBotMessage $message) {
    return;
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
          'view'=>'data'
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

        $data = $story['data'];
        if (!$data || $story['class'] !== "PhabricatorFeedStoryDifferential") {
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
          if (isset($projects[$project_name])) {
            $channel = id(new PhabricatorBotChannel())
              ->setName($projects[$project_name]);
            $recipients = array($channel);
          }

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
            $recipients = array();
            foreach($usernames as $username) {
              $recipients[] = id(new PhabricatorBotUser())
                ->setName($username);
            }
            break;
          case DifferentialAction::ACTION_ACCEPT:
          case DifferentialAction::ACTION_REJECT:
            // Only to the author of the revision
            $author = $handles[$author_phid];
            $recipient = id(new PhabricatorBotUser())
              ->setName($author->getName());
            $recipients = array($recipient);
            break;
          default:
            // Don't send message to anyone
            $recipients = array();
            break;
          }
        }

        foreach ($recipients as $recipient) {
          $this->writeMessage(
            id(new PhabricatorBotMessage())
            ->setCommand('MESSAGE')
            ->setTarget($recipient)
            ->setBody($message)
          );
        }
      }
    }
  }

  private function printRevision($revision_id) {
    $revisions = $this->getConduit()->callMethodSynchronous(
      'differential.query',
      array(
        'ids'   => array($revision_id),
      ));
    $revision = $revisions[0];
    return "D".$revision_id.": ".$revision['title']." - ".$revision['uri'];
  }
}
