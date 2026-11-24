<!DOCTYPE html>
<html lang="es" data-tema="adultos" data-modo="claro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Sistema de Gestión de Laboratorios FICCT - UAGRM">
    <title>Laboratorios FICCT</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@400;600;700&family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script>
        // Configuración que React necesita para funcionar en cualquier carpeta del servidor
        // (ej: /inf513/grupo08sa/proyecto2). Se calcula en el servidor con url().
        window.APP = {
            base: @json(rtrim(parse_url(url('/'), PHP_URL_PATH) ?? '', '/')),
            api: @json(url('/api')),
            nombre: @json(config('app.name')),
        };
        // Aplica el tema guardado ANTES de pintar la página (evita parpadeo)
        try {
            var p = JSON.parse(localStorage.getItem('preferencias') || '{}');
            var h = new Date().getHours();
            var modo = p.modo === 'auto' || !p.modo ? (h >= 7 && h < 19 ? 'claro' : 'oscuro') : p.modo;
            var r = document.documentElement;
            r.dataset.tema = p.tema || 'adultos';
            r.dataset.modo = modo;
            if (p.contraste) r.dataset.contraste = 'alto';
            r.style.fontSize = (p.fuente || 100) + '%';
        } catch (e) {}
    </script>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/main.jsx'])
</head>
<body>
    <noscript>Este sistema necesita JavaScript habilitado.</noscript>
    <div id="root"></div>
</body>
</html>
