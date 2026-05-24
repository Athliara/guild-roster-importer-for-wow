(function () {
  'use strict';

  const edgeInset = 20;

  function escapeSelector(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(value);
    }

    return value.replace(/["\\]/g, '\\$&');
  }

  function getFrameCenter(boardRect, node) {
    const frame = node.querySelector('.wgrp-talent-node-frame') || node;
    const rect = frame.getBoundingClientRect();

    return {
      x: rect.left - boardRect.left + rect.width / 2,
      y: rect.top - boardRect.top + rect.height / 2,
      radius: Math.max(rect.width, rect.height) / 2
    };
  }

  function positionEdge(board, edge) {
    const fromCell = edge.getAttribute('data-wgrp-edge-from');
    const toCell = edge.getAttribute('data-wgrp-edge-to');
    if (!fromCell || !toCell) {
      return;
    }

    const fromNode = board.querySelector('[data-wgrp-node-cell="' + escapeSelector(fromCell) + '"]');
    const toNode = board.querySelector('[data-wgrp-node-cell="' + escapeSelector(toCell) + '"]');
    if (!fromNode || !toNode) {
      return;
    }

    const boardRect = board.getBoundingClientRect();
    const from = getFrameCenter(boardRect, fromNode);
    const to = getFrameCenter(boardRect, toNode);
    const dx = to.x - from.x;
    const dy = to.y - from.y;
    const distance = Math.sqrt(dx * dx + dy * dy);
    if (distance <= 1) {
      return;
    }

    const startInset = Math.min(distance / 2 - 1, Math.max(edgeInset, from.radius + 8));
    const endInset = Math.min(distance / 2 - 1, Math.max(edgeInset, to.radius + 8));
    const startX = from.x + (dx / distance) * startInset;
    const startY = from.y + (dy / distance) * startInset;
    const length = Math.max(0, distance - startInset - endInset);
    const angle = Math.atan2(dy, dx);

    edge.style.left = startX.toFixed(2) + 'px';
    edge.style.top = startY.toFixed(2) + 'px';
    edge.style.width = length.toFixed(2) + 'px';
    edge.style.transform = 'rotate(' + angle.toFixed(6) + 'rad)';
  }

  function positionTalentEdges() {
    document.querySelectorAll('[data-wgrp-talent-board]').forEach(function (board) {
      board.querySelectorAll('.wgrp-talent-edge').forEach(function (edge) {
        positionEdge(board, edge);
      });
    });
  }

  function schedulePositioning() {
    window.requestAnimationFrame(function () {
      positionTalentEdges();
      window.requestAnimationFrame(positionTalentEdges);
    });
  }

  function activateSpecTab(tab) {
    const paneId = tab.getAttribute('data-wgrp-spec-tab');
    const card = tab.closest('.wgrp-spec-card');
    if (!paneId || !card) {
      return;
    }

    const pane = card.querySelector('#' + escapeSelector(paneId));
    if (!pane) {
      return;
    }

    card.querySelectorAll('[data-wgrp-spec-tab]').forEach(function (candidate) {
      const isActive = candidate === tab;
      candidate.classList.toggle('is-active', isActive);
      candidate.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    card.querySelectorAll('.wgrp-spec-pane').forEach(function (candidate) {
      const isActive = candidate === pane;
      candidate.classList.toggle('is-active', isActive);
      candidate.hidden = !isActive;
    });

    schedulePositioning();
    window.setTimeout(schedulePositioning, 80);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', schedulePositioning);
  } else {
    schedulePositioning();
  }

  window.addEventListener('load', schedulePositioning);
  window.addEventListener('resize', schedulePositioning);
  document.addEventListener('click', function (event) {
    const specTab = event.target.closest('[data-wgrp-spec-tab]');
    if (specTab) {
      event.preventDefault();
      activateSpecTab(specTab);
    }
  });

  if (typeof window.ResizeObserver === 'function') {
    const observer = new window.ResizeObserver(schedulePositioning);
    document.querySelectorAll('[data-wgrp-talent-board]').forEach(function (board) {
      observer.observe(board);
    });
  }

  document.querySelectorAll('.wgrp-talent-node-icon').forEach(function (image) {
    if (!image.complete) {
      image.addEventListener('load', schedulePositioning, { once: true });
      image.addEventListener('error', schedulePositioning, { once: true });
    }
  });
}());
