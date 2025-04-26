# Selenium Scanner

A Node.js application that listens to an MQTT queue for website scanning results and takes screenshots of the websites.

## Requirements

- Node.js (v14 or higher)
- RabbitMQ server (for AMQP)

## Installation

1. Clone this repository
2. Install dependencies:

```bash
npm install
```

## Configuration

The application can be configured using environment variables:

- `MQTT_BROKER`: The AMQP URL (default: `amqp://127.0.0.1`)
- `MQTT_USERNAME`: The AMQP username (default: `guest`)
- `MQTT_PASSWORD`: The AMQP password (default: `guest`)
- `QUEUE_NAME`: The queue to listen to (default: `scanner_results`)

## Usage

1. Start the application:

```bash
npm start
```

Or with custom configuration:

```bash
MQTT_BROKER=amqp://your-broker MQTT_USERNAME=user MQTT_PASSWORD=pass QUEUE_NAME=your_queue npm start
```

2. The application will connect to the RabbitMQ server and start listening for messages.
3. When a message is received with the format:

```json
{
  "ip": "116.202.24.113",
  "port": 80,
  "service": "http",
  "metadata": {
    "timestamp": "2025-04-20T12:11:43.131Z",
    "statusCode": 200,
    "headers": {},
    "server": "nginx",
    "contentType": "text/html",
    "title": "example.com"
  }
}
```

4. It will take a screenshot of the website and save it to the `screenshots` directory.

## Screenshots

Screenshots are saved in the `php/screenshots` directory with filenames in the format: `{IP}_{PORT}_{TIMESTAMP}.png` 