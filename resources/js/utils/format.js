/**
 * Format a currency amount from cents to dollars
 * @param {number} cents - Amount in cents
 * @returns {string} Formatted dollar amount
 */
export const formatCurrency = (cents) => {
  if (cents === undefined || cents === null) {
    return '0.00';
  }
  // Ensure we're working with a number
  const amountInCents = Number(cents);
  if (isNaN(amountInCents)) {
    return '0.00';
  }
  
  const dollars = Math.abs(amountInCents) / 100;
  return dollars.toFixed(2);
}; 