    </main>
  </div>
</div>

<?php if (!empty($ssms_guard_bfcache)): ?>
<script>
// If this page is ever restored from the browser's back-forward cache
// (bfcache) after a logout happened in between, force a real reload so
// the server re-checks the session instead of showing this stale snapshot.
window.addEventListener('pageshow', function (event) {
  if (event.persisted) {
    window.location.reload();
  }
});
</script>
<?php endif; ?>

<script src="<?= $base ?>assets/app.js"></script>
</body>
</html>
