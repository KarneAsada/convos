<?php

/**
 * A small messaging API for a Convos RESTful service
 * It provides the following endpoints
 *
 * GET  /api/convos - a list of Convo objects belonging to the user
 * POST /api/convo - create a new Convo thread
 * POST /api/convo/:id - reply to a Convo thread
 * GET  /api/convo/:id - get a Convo thread and all of it's messages
 * POST /api/token - get a JSON Web Token to use as authorization
 */

// Set the timezone
date_default_timezone_set('UTC');

// Include the Slim microframework and instantiate
require_once 'vendor/Slim/Slim.php';
\Slim\Slim::registerAutoloader();
$app = new \Slim\Slim();

// Include the db model and instantiate
require_once 'Convos.php';
$convos = new Convos();

// Include the JWT library
require_once 'vendor/JWT/JWT.php';
$jwtKey = 'veryverysecret';

/**************************************
 * Routes
 **************************************/

/**
 * GET /api/convos
 * Return a list of convo objects for your user
 */
$app->get('/api/convos', 'auth', function() use ($convos, $app) {
  $list = $convos->getList();
  $app->contentType('application/json');
  echo json_encode($list);
});

/**
* POST /api/convo
* Create a new convo
*/
$app->post('/api/convo(/:id)', 'auth', function($id = 0) use ($convos, $app) {

  // Validate post variables
  $errors = [];
  $inputs = [];

  // Check existence of to, subject and body parameters
  if (($inputs['recipient'] = $app->request->params('recipient')) === null) {
    $errors[] = 'Missing recipient variable';
  }

  if ( ! $convos->checkUserId($inputs['recipient']) ) {
    $errors[] = 'Specified recipient does not exist';
  }

  if (($inputs['subject'] = $app->request->params('subject')) === null) {
    $errors[] = 'Missing subject variable';
  }

  if (($inputs['body'] = $app->request->params('body')) === null) {
    $errors[] = 'Missing body variable';
  }

  // Check lengths of subject and body
  if (strlen($inputs['subject']) > 140) {
    $errors[] = 'Subject length is limited to 140 characters';
  }

  if (strlen($inputs['body']) > 64000) {
    $errors[] = 'Body length is limited to 64k characters';
  }

  // Add parent id in the case of a reply to a parent thread
  $inputs['parent'] = $id;

  if ( ! $errors ) {

    if ($convos->create( $inputs ) === false) {
      $app->response->setStatus(500);
      $errors[] = 'An unknown error was encountered';
    }

  } else {
    // Set status to 400 if there are errors
    $app->response->setStatus(400);
  }

  $app->contentType('application/json');
  echo json_encode(postResponse($errors));
});

/**
 * GET /api/convo/:id
 * Return a single convo and it's entire thread
 */
$app->get('/api/convo/:id', 'auth', function($id) use ($convos, $app) {
  $response = $convos->getThread($id);

  // Return an error if there is no thread associated with the provide id
  if (empty($response)) {
    $app->response->setStatus(400);
    $response = postResponse('No such convo with id #'.$id);
  }

  $app->contentType('application/json');
  echo json_encode($response);
});

/**
 * POST /api/token
 * Get a JWT for the provided user
 */
$app->post('/api/token', function() use ($app, $convos, $jwtKey) {
  $errors = [];
  $userId = null;

  // Check existence of user param
  if (($userId = $app->request->params('user')) == null) {
    $errors[] = 'Missing user variable';
  }

  if ( ! $convos->checkUserId($userId) ) {
    $errors[] = 'Specified user does not exist';
  }

  $response = '';
  if ( ! $errors ) {

    $token = array(
        "iss" => "*",
        "aud" => "*",
        "iat" => time(),
        "exp" => time()+3600,
        "userId" => $userId,
    );
    echo JWT::encode($token, $jwtKey);

  } else {

    // Set status to 400 if there are errors
    $app->response->setStatus(401);
    $app->contentType('application/json');
    echo json_encode(postResponse($errors));
  }
});

// Start app
$app->run();


/**************************************
 * Helper functions used by the app
 **************************************/

/**
 * Middleware to authenticate JWT
 * @return void
 */
function auth() {
  try {
    $app = \Slim\Slim::getInstance();
    $token = $app->request->headers->get('Authorization');
    $decoded = JWT::decode($token, $jwtKey, array('HS256'));

    Convos::setUserId( $decoded->userId );
  } catch (Exception $e) {
    header('HTTP/1.0 401 Unauthorized');
    header('Content-type: application/json');
    echo json_encode(postResponse('Authorization Failure: '.$e->getMessage()));
    exit;
  }
}

/**
 * Helper function to format post response
 * @param array
 */
function postResponse( $errors = null ) {
  if ($errors) {
    return [
        'status'  => 'error',
        'message' => 'Your request encountered an error',
        'errors'  => $errors,
      ];
  } else {
    return [
        'status'  => 'success',
        'message' => 'Your request was successfully processed',
      ];
  }
}
