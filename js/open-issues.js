/**
 * Returns issues that remain open after all PRs have been merged.
 * A PR closes an issue if their digits are a rotation of each other
 * (considering leading zero padding), unless the numbers are exactly equal.
 *
 * @param {number[]} issues - Array of issue numbers
 * @param {number[]} prs - Array of pull request numbers
 * @returns {number[]} - Array of issue numbers that remain open
 */
function getOpenIssues(issues, prs) {
  /**
   * Checks if two numbers are digit rotations of each other.
   * Shorter number is padded with leading zeros to match lengths.
   */
  function isRotation(a, b) {
    if (a === b) return false; // exact same number does not close

    const sA = String(a);
    const sB = String(b);
    const maxLen = Math.max(sA.length, sB.length);

    // Pad the shorter string with leading zeros
    const paddedA = sA.padStart(maxLen, '0');
    const paddedB = sB.padStart(maxLen, '0');

    // Check if paddedB is a rotation of paddedA
    if (paddedA.length !== paddedB.length) return false;
    return (paddedA + paddedA).includes(paddedB);
  }

  return issues.filter(issue => {
    // Check if any PR closes this issue
    for (const pr of prs) {
      if (isRotation(issue, pr)) {
        return false; // issue is closed
      }
    }
    return true; // issue remains open
  });
}

// Test cases
// Test 1: getOpenIssues([123, 234], [231]) should return [234]
console.log('Test 1:', JSON.stringify(getOpenIssues([123, 234], [231])));

// Test 2: getOpenIssues([123, 345, 16], [345, 231]) should return [345, 16]
console.log('Test 2:', JSON.stringify(getOpenIssues([123, 345, 16], [345, 231])));

// Test 3: getOpenIssues([456, 332, 12, 15], [201, 945, 180]) should return [456, 332, 15]
console.log('Test 3:', JSON.stringify(getOpenIssues([456, 332, 12, 15], [201, 945, 180])));

// Test 4: getOpenIssues([12, 115, 296, 170, 24], [17, 18, 19, 20, 21]) should return [115, 296, 24]
console.log('Test 4:', JSON.stringify(getOpenIssues([12, 115, 296, 170, 24], [17, 18, 19, 20, 21])));

// Test 5: getOpenIssues([19, 95, 422, 395, 754, 102, 296, 709, 237, 4400, 1802], [395, 440, 9001, 95, 242, 21, 287, 169, 14])
// should return [95, 395, 754, 296, 709, 237, 1802]
console.log('Test 5:', JSON.stringify(getOpenIssues(
  [19, 95, 422, 395, 754, 102, 296, 709, 237, 4400, 1802],
  [395, 440, 9001, 95, 242, 21, 287, 169, 14]
)));
