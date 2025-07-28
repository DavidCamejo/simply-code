// Back to Top Button JS
document.addEventListener("DOMContentLoaded", function(event) {
    var offset = 500, duration = 400;
    var toTopButton = document.getElementById('toTop');

    window.addEventListener('scroll', function() {
	(window.pageYOffset > offset) ? toTopButton.style.display = 'block' : toTopButton.style.display = 'none';
    });

    toTopButton.addEventListener('click', function(event) {
	event.preventDefault();
	window.scroll({ top: 0, left: 0, behavior: 'smooth' });
    });
});
