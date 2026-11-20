'use strict';

const { Client, TopicMessageSubmitTransaction, PrivateKey } = require('@hashgraph/sdk');

async function main() {
  let input = '';
  for await (const chunk of process.stdin) input += chunk;
  const event = JSON.parse(input);
  const accountId = process.env.HEDERA_ACCOUNT_ID;
  const privateKeyText = process.env.HEDERA_PRIVATE_KEY;
  const topicId = process.env.HEDERA_TOPIC_ID;

  if (!accountId || !privateKeyText || !topicId) {
    throw new Error('HEDERA_ACCOUNT_ID, HEDERA_PRIVATE_KEY and HEDERA_TOPIC_ID are required.');
  }
  if (!/^[a-f0-9]{64}$/.test(event.document_hash || '')
      || !/^[a-f0-9]{64}$/.test(event.identity_hash || '')
      || !['APPROVED', 'FLAGGED'].includes(event.status)
      || typeof event.event_id !== 'string'
      || typeof event.recorded_at !== 'string') {
    throw new Error('Invalid event. Only fingerprints, verification status and event metadata are accepted.');
  }

  const privateKey = PrivateKey.fromString(privateKeyText);
  const client = Client.forTestnet().setOperator(accountId, privateKey);
  try {
    const safeMessage = JSON.stringify({
      version: 1,
      eventId: event.event_id,
      documentHash: event.document_hash,
      identityHash: event.identity_hash,
      status: event.status,
      recordedAt: event.recorded_at,
    });
    const response = await new TopicMessageSubmitTransaction()
      .setTopicId(topicId)
      .setMessage(safeMessage)
      .execute(client);
    const receipt = await response.getReceipt(client);
    const record = await response.getRecord(client);
    if (!receipt.topicId || !receipt.topicSequenceNumber) {
      throw new Error('Hedera returned a receipt without a topic sequence number.');
    }
    process.stdout.write(JSON.stringify({
      success: true,
      transaction_id: response.transactionId.toString(),
      topic_id: receipt.topicId.toString(),
      consensus_timestamp: record.consensusTimestamp.toString(),
      explorer_url: `https://hashscan.io/testnet/transaction/${encodeURIComponent(response.transactionId.toString())}`,
    }));
  } finally {
    client.close();
  }
}

main().catch((error) => {
  process.stderr.write(`${error.message || String(error)}\n`);
  process.stdout.write(JSON.stringify({ success: false, error: error.message || String(error) }));
  process.exitCode = 1;
});
