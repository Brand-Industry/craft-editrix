// Craft starts its web queue runner from the control panel's JavaScript,
// and only when a panel page loads. A job pushed by an AJAX request, like
// ours, would otherwise wait until the user opens another page. This asks
// Craft's own runner to start it now; it does nothing if Craft isn't there
// or the queue runs from a worker.
export const kickQueue = () => {
  try {
    window.Craft?.cp?.runQueue?.();
  } catch (err) {
    // The queue still starts on the next panel page load.
  }
};
