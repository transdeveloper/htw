/**
 * Note: Current implementation is temporary and should be improved.
 * 
 * Instead of saving screenshots locally, we should upload them to S3.
 * 
 * Future goal:
 * - Collect detailed metadata (body content, human-readable format, search-optimized format for vector search).
 * - Capture screenshots alongside metadata.
 * - Build a processing pipeline where servers perform deeper scans to gather as much metadata as possible.
 * 
 * Data storage strategy:
 * - Store complete raw metadata and screenshots in Cassandra (optimized for large-scale storage).
 * - Store search-optimized metadata in PostgreSQL (for fast indexing and querying).
 */
const amqp = require('amqplib');
const puppeteer = require('puppeteer');
const fs = require('fs-extra');
const path = require('path');
const moment = require('moment');

// Configuration
const AMQP_URL = process.env.MQTT_BROKER || 'amqp://127.0.0.1';
const AMQP_USERNAME = process.env.MQTT_USERNAME || 'guest';
const AMQP_PASSWORD = process.env.MQTT_PASSWORD || 'guest';
const QUEUE_NAME = process.env.QUEUE_NAME || 'scanner_results';
const SCREENSHOTS_DIR = path.join(__dirname, 'php/screenshots');

// Ensure screenshots directory exists
fs.ensureDirSync(SCREENSHOTS_DIR);

async function takeScreenshot(ip, port, metadata = {}, attempt = 1) {
    let browser;
    let protocol = metadata.service === 'https' ? 'https' : 'http';
    if (port == 443) protocol = 'https';
    const url = `${protocol}://${ip}:${port}`;
  
    try {
      // console.log(`Taking screenshot of ${url} (attempt ${attempt})`);
  
      browser = await puppeteer.launch({
        headless: true,
        executablePath: puppeteer.executablePath(),
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
        ignoreHTTPSErrors: true
      });
  
      const page = await browser.newPage();
  
      await page.setUserAgent(
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ' +
        'AppleWebKit/537.36 (KHTML, like Gecko) ' +
        'Chrome/123.0.0.0 Safari/537.36'
      );
      await page.setViewport({ width: 1366, height: 768, deviceScaleFactor: 1 });
      await page.emulateTimezone('UTC');
      await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });
  
      let response = null;
      try {
        response = await page.goto(url, {
          waitUntil: 'domcontentloaded',
          timeout: 10000
        });
        console.log(`Loaded: ${response?.url()} (status: ${response?.status()})`);
      } catch (navErr) {
        console.warn(`Navigation failed for ${url}: ${navErr.message}`);
      }
  
      await page.waitForTimeout(700); // Wait regardless of nav success
  
      const timestamp = metadata.timestamp ?
        moment(metadata.timestamp).format('YYYYMMDD-HHmmss') :
        moment().format('YYYYMMDD-HHmmss');
  
      const filename = `${ip}_${port}_${timestamp}.png`;
      const screenshotPath = path.join(SCREENSHOTS_DIR, filename);
  
      try {
        await page.screenshot({ path: screenshotPath, fullPage: true });
        console.log(`Screenshot saved: ${screenshotPath}`);
        return screenshotPath;
      } catch (ssErr) {
        console.error(`Screenshot failed for ${url}: ${ssErr.message}`);
        return null;
      }
  
    } catch (error) {
      console.error(`Unexpected error for ${url}:`, error);
      if (attempt === 1) {
        console.log(`Retrying ${url}...`);
        return takeScreenshot(ip, port, metadata, 2);
      }
      return null;
    } finally {
      try {
        if (browser) await browser.close();
      } catch (closeErr) {
        console.warn('Error closing browser:', closeErr.message);
      }
    }
  }

async function processMessage(message) {
  try {
    if (!message) {
      console.log('Received empty message, skipping');
      return;
    }

    const content = JSON.parse(message.content.toString());
    console.log('Received message:', JSON.stringify(content, null, 2));

    if (!content.ip || !content.port) {
      console.error('Invalid message format: missing ip or port');
      return;
    }

    await takeScreenshot(content.ip, content.port, content.metadata);
  } catch (error) {
    console.error('Error processing message:', error);
  }
}

async function startConsumer() {
  try {
    console.log(`Connecting to AMQP broker: ${AMQP_URL}`);

    const { hostname, port, pathname } = new URL(AMQP_URL);

    const connection = await amqp.connect({
      protocol: 'amqp',
      hostname,
      port: port || 5672,
      username: AMQP_USERNAME,
      password: AMQP_PASSWORD,
      vhost: pathname === '/' ? '/' : pathname.replace(/^\//, '')
    });

    const channel = await connection.createChannel();
    await channel.assertQueue(QUEUE_NAME, { durable: true });

    console.log(`Waiting for messages from queue: ${QUEUE_NAME}`);
    channel.prefetch(1);

    channel.consume(QUEUE_NAME, async (message) => {
      try {
        await processMessage(message);
        channel.ack(message);
      } catch (err) {
        console.error('Error in consumer handler:', err);
        channel.nack(message, false, false); // discard bad messages
      }
    });

    process.on('SIGINT', async () => {
      console.log('Shutting down...');
      await channel.close();
      await connection.close();
      process.exit(0);
    });

  } catch (error) {
    console.error('AMQP connection error:', error);
    process.exit(1);
  }
}

// Start the consumer
startConsumer().catch(console.error);