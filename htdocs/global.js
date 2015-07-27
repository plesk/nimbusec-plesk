function updateState() {
	var elems = document.getElementsByName("active[]");
	var target = document.getElementById("act").checked;

	for (var i = 0; i<elems.length; i++) {
		elems[i].checked = target;
	}
}