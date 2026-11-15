const DRAG_THRESHOLD_PX = 3;

/** The state of a mouse or touch press on a marker; null when the press is not the primary button on a marker. */
export function beginDrag(event, marker, number) {
  if (!marker || !event.isPrimary || 0 !== event.button) {
    return null;
  }
  return { number, startX: event.clientX, startY: event.clientY, isMoving: false };
}

/** Whether the pointer has travelled far enough from where the press began for it to count as a drag, not a click. */
export function hasPassedDragThreshold(drag, event) {
  return Math.hypot(event.clientX - drag.startX, event.clientY - drag.startY) >= DRAG_THRESHOLD_PX;
}
