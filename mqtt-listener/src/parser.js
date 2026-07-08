/**
 * Parses the semicolon-separated DRGT messages into a normalized object.
 *
 *   JPCONFIG;<jpId>;<level>;<jpType>;<jpName>;<prizeName>;<CasID>
 *   JPUPDATE;<jpId>;<level>;<_>;<_>;<jpValue>;<jpShared>;<_>;<CasID>
 *
 * Returns null for unknown or malformed messages.
 */
function parseMessage(raw) {
  if (typeof raw !== 'string' || raw.trim() === '') {
    return null;
  }

  const parts = raw.split(';');
  const type = parts[0];

  if (type === 'JPCONFIG') {
    if (parts.length < 7) return null;
    return {
      type,
      jpId: parts[1],
      level: parts[2],
      jpType: parts[3],
      jpName: parts[4],
      prizeName: parts[5],
      casId: parts[6],
    };
  }

  if (type === 'JPUPDATE') {
    if (parts.length < 9) return null;
    return {
      type,
      jpId: parts[1],
      level: parts[2],
      jpValue: parts[5],
      jpShared: parts[6],
      casId: parts[8],
    };
  }

  return null;
}

module.exports = { parseMessage };
