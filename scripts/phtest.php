#!/usr/bin/env php
<?php

$root = dirname(dirname(dirname(__FILE__)));
require_once $root.'/phabricator/scripts/__init_script__.php';

abstract class PhabricatorMockBotHandler {
  private $conduit;
  // TODO read from irc.json
  private $config = array(
    'conduit.uri' => 'http://phabricator.kepler22b/',
    'conduit.user' => 'phabot',
    'conduit.cert' => 'b3bc23ki76bh4mszgu2fb3ygg322s2zqrkhfu65ixbn6paalarcfbogzul4fkbsmdwic5nwu3byyct6zkneq6jnvqncra3wnmla54xhyni6fi5go7sga3ymjn6bu77fsxlli4o5qp2wilwz22ypnekdoousja7ulij3epmdtdwzgay62x3paeff2mls4ir3bodwl3k24lozrudm7uklnkyhr2ate7ummgaiwbbh7a2cu4xbrmguaypofiigybsu',
    'notification.projects' => array('teste' => '#foo')
  );

  protected function writeMessage(PhabricatorBotMessage $message) {
    // do nothing
  }

  protected function getConduit() {
    $conduit_uri = $this->getConfig('conduit.uri');
    if (!$this->conduit) {
      $conduit_user = $this->getConfig('conduit.user');
      $conduit_cert = $this->getConfig('conduit.cert');

      // Normalize the path component of the URI so users can enter the
      // domain without the "/api/" part.
      $conduit_uri = new PhutilURI($conduit_uri);

      $conduit_host = (string)$conduit_uri->setPath('/');
      $conduit_uri = (string)$conduit_uri->setPath('/api/');

      $conduit = new ConduitClient($conduit_uri);
      $response = $conduit->callMethodSynchronous(
        'conduit.connect',
        array(
          'client'            => 'PhabricatorBot',
          'clientVersion'     => '1.0',
          'clientDescription' => php_uname('n').':'.'phabot',
          'host'              => $conduit_host,
          'user'              => $conduit_user,
          'certificate'       => $conduit_cert,
        ));

      $this->conduit = $conduit;
    }

    return $this->conduit;
  }

  protected function getConfig($key, $default = null) {
    return idx($this->config, $key, $default);
  }

  abstract function runBackgroundTasks();
}

class INdTNotificationHandlerMock extends INdTNotificationHandler {
  function __construct($number_of_stories) {
    $this->startupDelay = 0;

    $latest = $this->getConduit()->callMethodSynchronous(
      'feed.query',
      array(
        'limit' => $number_of_stories+1
      )
    );

    // Trick the bot and set lastSeenChronoKey to the **oldest** chrono key
    foreach ($latest as $story) {
      if ($this->lastSeenChronoKey === 0
        || $story['chronologicalKey'] < $this->lastSeenChronoKey) {
        $this->lastSeenChronoKey = $story['chronologicalKey'];
      }
    }
  }

  protected function writeMessage(PhabricatorBotMessage $message) {
    var_dump($message);
  }
}

/**
 * Instructions
 *
 * ExtendedIRCDifferentialNotificationHandler should extend PhabricatorMockBotHandler
 * Change $startupDelay and $lastSeenChronoKey to protected
 * $mock = new IRCBotMock();
 * $mock->run();
 */
class IRCBotMock {
  public function run() {
    $mock = new INdTNotificationHandlerMock(1);
    $mock->runBackgroundTasks();
  }
}

/**
 * Instructions
 *
 * $mock = new GetCommitDataMock();
 * $mock->run();
 */
class GetCommitDataMock 
  extends DifferentialGetCommitDataConduitAPIMethod {

    public static function getMockConduitAPIRequest() {
      $request = new ConduitAPIRequest(array('revision_id' => 4));
      $user = id(new PhabricatorUser())
        ->loadOneWhere('username = %s', 'phabot');
      $request->setUser($user);

      return $request;
    }

    public function run() {
      $request = self::getMockConduitAPIRequest();
      $data = $this->execute($request);
      var_dump($data);
      return $data;
    }
}

$a = new GetCommitDataMock();
// $a = new IRCBotMock();
$a->run();
