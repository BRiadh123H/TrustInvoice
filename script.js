'use strict';

document.querySelectorAll('input[type="file"]').forEach((input) => {
  const label = document.querySelector(`[data-for="${input.id}"]`);
  const container = input.closest('.file-drop');
  const showFile = (file) => {
    if (label) label.textContent = file ? file.name : 'Choose a file or drop it here';
  };
  input.addEventListener('change', () => showFile(input.files[0]));
  container?.addEventListener('dragover', (event) => {
    event.preventDefault();
    container.classList.add('dragging');
  });
  container?.addEventListener('dragleave', () => container.classList.remove('dragging'));
  container?.addEventListener('drop', (event) => {
    event.preventDefault();
    container.classList.remove('dragging');
    const files = event.dataTransfer?.files;
    if (!files?.length) return;
    input.files = files;
    showFile(files[0]);
  });
});

document.getElementById('upload-form')?.addEventListener('submit', (event) => {
  const invoice = document.getElementById('invoice-file');
  const po = document.getElementById('po-file');
  if (!invoice?.files?.length || !po?.files?.length) {
    event.preventDefault();
    window.alert('Please choose both an invoice and its purchase order.');
    return;
  }
  const button = document.getElementById('submit-button');
  if (button) {
    button.disabled = true;
    button.textContent = 'Analyzing securely…';
  }
});
