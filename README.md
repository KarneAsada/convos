# Convos API

This is a small API that can be used to read and send short, threaded messages
between users. It's built using SQLite, the micro-framework Slim
(http://www.slimframework.com/) for routing and a JSON Web Token library
called php-jwt (https://github.com/firebase/php-jwt).

## Running it
Download the code and run: ```php -S localhost:5000``` to run the server on
http://localhost:5000

## The DB
I chose an SQLite db for portability. A more robust RDBMS like MySQL or PostgreSQL
would be a better choice for production, but for demonstration purposes
SQLite works well.

The database is composed of two tables:

### users
A very simple table useful for mocking purposes
```
user_id INTEGER PRIMARY KEY
name TEXT
```

### convos
This table holds the conversation data. There is one row per message, with a
self-referential field ```parent_id``` that refers to a message's parent convo.
```
convo_id INTEGER PRIMARY KEY
parent_id INTEGER DEFAULT 0
sender_id INTEGER
recipient_id INTEGER
subject TEXT
body TEXT
tstamp INTEGER,
hasBeenRead INTEGER DEFAULT 0
```

I indexed the ```parent_id```, ```sender_id```, and ```recipient_id``` fields.

## Authorization
### POST /api/token
#### parameters: user
The API uses a very simple JWT authorization scheme. Since password based auth
is beyond the scope of this project, authorization is done simply by posting
to the JWT endpoint and passing a user parameter, like so:
```
curl --data "user=1" http://localhost:5000/api/token
```

Then each request to the API must be accompanied by an ```Authorization```
header with the provided JWT, like so:

```
curl -X GET -H "Authorization: eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiIqIiwiYXVkIjoiKiIsImlhdCI6MTQzMzYzMTgzMSwiZXhwIjoxNDM2MjIzODMxLCJ1c2VySWQiOiIxIn0.yubF3dUfYGeqI9HmuyeG-sq-it0zVXHiH-ThThJvxgU" http://localhost:5000/api/convos
```

Failures to authorize result in a 401 error.

## API Endpoints

The following endpoints are specified below with their request and response
formats. All of the responses are in JSON.

### List convos
#### GET /api/convos

This will return an array of Convo threads, containing the following fields:

- id: the id of the convo thread
- sender: the name of the sender
- recipient: the name of the recipient
- subject: the subject of the thread
- date: the date the thread was created (UTC)

An example response:
```json
[{
    "id": "3",
    "sender": "Joe",
    "recipient": "Dan",
    "subject": "I like your socks!",
    "datetime": "Wed, 13 May 2015 16:18:40 +0000"
}]
```

### Read one convo thread
#### GET /api/convo/:id

Retrieves the contents of one convo thread and marks all messages intended for
the recipient as read. The url parameter ```:id``` represents the id of the
the convo that the user can get from the List endpoint.

The response contains an object with the following fields:
- id: the id of the convo thread
- subject: the subject of the thread
- messages: an array of message objects that belong to the thread

A message object has the following fields:
- sender: the name of the sender
- recipient: the name of the recipient
- body: the contents of the message
- date: the date the message was sent (UTC)

An example response:
```json
{
    "id": "3",
    "subject": "I like your socks!",
    "messages": [{
        "sender": "Joe",
        "recipient": "Dan",
        "body": "Those are some sweet socks, bro",
        "read": true,
        "datetime": "Wed, 13 May 2015 16:18:40 +0000"
    }, {
        "sender": "Dan",
        "recipient": "Joe",
        "body": "Thanks, bro. I sewed them myself with organic cotton.",
        "read": false,
        "datetime": "Wed, 13 May 2015 16:29:20 +0000"
    }]
}
```

### Create a new convo thread
#### POST /api/convo
##### parameters: recipient, subject, body

This request creates a new convo object. The following parameters are required:
- recipient: the user id of the message's recipient
- subject: the subject of the message
- body: the content of the message

A successful post receives a 200 response with the following JSON object:
```json
{
    "status": "success",
    "message": "Your request was successfully processed"
}
```

### Reply to a convo thread
#### POST /api/convo/:id
##### parameters: recipient, subject, body

This endpoint is very similar to the Create endpoint the addition of the
```:id``` url parameter, which is the id of the convo.

### Errors

Errors receive a 400 response with JSON object containing the following fields:
- status: error or success
- message: a message announcing that an error was encountered
- errors: an array of errors encountered

```json
{
    "status": "error",
    "message": "Your request encountered an error",
    "errors": [
        "Missing subject variable",
        "Missing body variable"
    ]
}
```

### Notes
Due to the threaded nature of Convos, I intentionally left out the UPDATE and
DELETE endpoints of the API. Neither seemed appropriate, since the convos are
messages like email and once they are sent, they should be permanent.

* I also included a Postman collection for easy testing
```convos.json.postman_collection```
