<?php

/**
 * A model for interfacing with the Convos data
 */
class Convos {

  const DSN       = 'sqlite:convos.sqlite3';
  private $db     = null;
  static $userId  = null;

  /**
   * A constructor that connects to the db
   */
  public function __construct() {
    if ($this->db === null) {
      $this->_connect();
    }
  }

  /**
   * Sets the user id of the current user
   * @param int $id
   */
  public static function setUserId( $id ) {
    self::$userId = $id;
  }

  /**
   * Returns a list of convo thread objects
   * @return array
   */
  public function getList() {
    $sth = $this->db->prepare(
                         'SELECT c.*, s.name AS "sender", r.name AS "recipient"
                          FROM convos c
                          LEFT JOIN users s ON c.sender_id = s.user_id
                          LEFT JOIN users r ON c.recipient_id = r.user_id
                          WHERE c.parent_id = 0
                          AND (c.sender_id = :user OR c.recipient_id = :user)
                          ORDER BY c.convo_id DESC
                          ');

    $sth->execute([':user' => self::$userId]);

    $results = [];
    while($row = $sth->fetch(PDO::FETCH_ASSOC)) {
      $results[] = [
        'id'        => $row['convo_id'],
        'sender'    => $row['sender'],
        'recipient' => $row['recipient'],
        'subject'   => $row['subject'],
        'datetime'  => date('r', $row['tstamp']),
        ];
    }

    return $results;
  }

  /**
   * Returns one convo thread and it's associated messages
   * @param int $id the id of the convo thread to return
   */
  public function getThread( $id ) {

    // Mark this thread as read by recipient
    $this->markRead( $id );

    $sth = $this->db->prepare(
                         'SELECT c.*, s.name AS "sender", r.name AS "recipient"
                          FROM convos c
                          LEFT JOIN users s ON c.sender_id = s.user_id
                          LEFT JOIN users r ON c.recipient_id = r.user_id
                          WHERE (c.convo_id = :id OR c.parent_id = :id)
                          AND (c.sender_id = :user OR c.recipient_id = :user)
                          ORDER BY c.convo_id ASC
                          ');

    $sth->execute([':user' => self::$userId, ':id' => $id]);

    $results = [];
    while($row = $sth->fetch(PDO::FETCH_ASSOC)) {

      if( empty($results) ) {
        $results = [
          'id' => $row['convo_id'],
          'subject' => $row['subject'],
          'messages' => []
          ];
      }

      $results['messages'][] = [
        'sender'    => $row['sender'],
        'recipient' => $row['recipient'],
        'body'      => $row['body'],
        'read'      => !!$row['hasBeenRead'],
        'datetime'  => date('r', $row['tstamp']),
        ];
    }

    return $results;
  }

  /**
   * Creates a new convo thread
   * @param  array $inputs An array of inputs:
   *   recipient, parent, subject, and body
   * @return boolean success
   */
  public function create( $inputs ) {

    if ($inputs['parent_id']) {
      $inputs['subject'] = null;
    }

    $sth = $this->db->prepare(
                         'INSERT INTO convos (
                            parent_id,
                            sender_id,
                            recipient_id,
                            subject,
                            body,
                            tstamp
                            )
                          VALUES (
                            :parent_id,
                            :sender_id,
                            :recipient_id,
                            :subject,
                            :body,
                            :tstamp
                            )
                          ');

    return $sth->execute([
      ':parent_id'    => $inputs['parent'],
      ':sender_id'    => self::$userId,
      ':recipient_id' => $inputs['recipient'],
      ':subject'      => $inputs['subject'],
      ':body'         => $inputs['body'],
      ':tstamp'       => time(),
      ]);
  }

  /**
   * Updates the convo messages specified by the parent id passed to be
   * marked read
   * @param int $id the parent id of the convo thread
   * @return boolean success
   */
  public function markRead( $id ) {
    $sth = $this->db->prepare(
                         'UPDATE convos SET hasBeenRead = 1
                          WHERE (convo_id = :id OR parent_id = :id)
                          AND recipient_id = :user
                          ');

    return $sth->execute([
      ':id'      => $id,
      ':user'    => self::$userId,
      ]);
  }

  /**
   * Drops and creates the Convo tables and indexes and loads data from CSVs
   */
  public function initialize() {

    // Users table
    $this->db->exec('DROP TABLE IF EXISTS users');
    $this->db->exec('CREATE TABLE users (
                      user_id INTEGER PRIMARY KEY,
                      name TEXT
                      )');

    $this->db->exec('DROP TABLE IF EXISTS convos');
    $this->db->exec('CREATE TABLE IF NOT EXISTS convos (
                      convo_id INTEGER PRIMARY KEY,
                      parent_id INTEGER DEFAULT 0,
                      sender_id INTEGER,
                      recipient_id INTEGER,
                      subject TEXT,
                      body TEXT,
                      tstamp INTEGER,
                      hasBeenRead INTEGER DEFAULT 0
                      )');

    // Create indices
    $this->db->exec('CREATE INDEX parentIdx ON convos (parent_id)');
    $this->db->exec('CREATE INDEX senderIdx ON convos (sender_id)');
    $this->db->exec('CREATE INDEX recipientIdx ON convos (recipient_id)');

    // Import data
    $this->_importCSV( 'data/users.csv',  'users');
    $this->_importCSV( 'data/convos.csv', 'convos');
  }

  /**
   * Checks whether the supplied user id is valid
   * @param int $userId
   * @return boolean
   */
  public function checkUserId( $userId ) {
    $sth = $this->db->prepare('SELECT * FROM users WHERE user_id = :user_id');
    $sth->execute([':user_id' => $userId]);
    return !!count($sth->fetchAll());
  }

  /**
   * Checks whether the supplied parent id is valid
   * @param int $parentId
   * @return boolean
   */
  public function checkConvoId( $id ) {
    $sth = $this->db->prepare('SELECT * FROM convos WHERE convo_id = :id');
    $sth->execute([':id' => $id]);
    return !!count($sth->fetchAll());
  }

  /**
   * Private functions
   */

  /**
   * Connects to the SQLite db
   */
  private function _connect() {
    // Connect to SQLite
    try {
      $this->db = new PDO(self::DSN);
      $this->db->setAttribute(PDO::ATTR_ERRMODE,
                              PDO::ERRMODE_EXCEPTION
                              );
    } catch(PDOException $e) {
      // Print PDOException message
      echo $e->getMessage();
    }
  }

  /**
   * Imports data from a CSV file
   * @param string $csvFile
   * @param string $table
   */
  private function _importCSV( $csvFile, $table ) {

    // Open the CSV
    if (($csvHandle = fopen($csvFile, "r")) === FALSE) {
      throw new Exception('Cannot open CSV file');
    }

    $delim = ',';

    // Get field names
    $fields = array_map(function ($field){
                return strtolower(preg_replace("/[^A-Z_0-9]/i", '', $field));
              }, fgetcsv($csvHandle, 0, $delim));

    $insertFieldsStr = join(', ', $fields);
    $insertValuesStr = join(', ', array_fill(0, count($fields),  '?'));
    $insertSql = "INSERT INTO $table ($insertFieldsStr) VALUES ($insertValuesStr)";
    $insertSth = $this->db->prepare($insertSql);

    while (($data = fgetcsv($csvHandle, 0, $delim)) !== FALSE) {
      $insertSth->execute($data);
    }
  }
}

?>
