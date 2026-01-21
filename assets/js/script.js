"use strict";

document.addEventListener('DOMContentLoaded', function() {
    let input = document.getElementById('wca-file');
    let submit = document.getElementById('wca-submit');
    let response = document.getElementById('wca-notification');

    const MAX_FILE_SIZE = 5 * 1024 * 1024;

    response.innerHTML = '';
    submit.classList.remove('wca-visible');
    submit.classList.add('wca-hidden');

    input.addEventListener('change', function() {
        if(!input.files.length) {
            return 0;
        }

        let filePath = input.value;
        let allowedExtensions = /(\.pdf)$/i;

        if (!allowedExtensions.exec(filePath)) {
            // console.log('Invalid file type');
            response.innerHTML = '<span class="wca-response">Virhe: Vain PDF-tiedostot ovat sallittuja.</span>';
            input.value = '';
            submit.classList.remove('wca-visible');
            submit.classList.add('wca-hidden');
        } else if (input.size > MAX_FILE_SIZE) { 
            // console.log('File too large');
            response.innerHTML = '<span class="wca-response">Virhe: Tiedoston koko ylittää sallitun 5MB rajan.</span>';
            input.value = '';
            submit.classList.remove('wca-visible');
            submit.classList.add('wca-hidden');
        } else {
            // console.log('Valid file type');
            response.innerHTML = '';
            submit.classList.remove('wca-hidden');
            submit.classList.add('wca-visible');
        }
    });
});