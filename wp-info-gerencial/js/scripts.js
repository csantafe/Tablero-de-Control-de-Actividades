document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('graficoGerencial');
    if(!ctx) return; // Si no hay gráfica, no hacemos nada

    // Calculamos porcentajes para mostrarlos en los "tooltips" (etiquetas)
    const total = datosGrafico.ingresos + datosGrafico.gastos;
    const porcIngreso = total > 0 ? ((datosGrafico.ingresos / total) * 100).toFixed(2) : 0;
    const porcGasto = total > 0 ? ((datosGrafico.gastos / total) * 100).toFixed(2) : 0;

    let tipoGrafico = 'bar'; // Inicialmente es barras

    let miGrafico = new Chart(ctx, {
        type: tipoGrafico,
        data: {
            labels: [
                'Ingresos (' + porcIngreso + '%)', 
                'Gastos (' + porcGasto + '%)'
            ],
            datasets: [{
                label: 'Totales',
                data: [datosGrafico.ingresos, datosGrafico.gastos],
                backgroundColor: [
                    'rgba(75, 192, 192, 0.6)', // Verde para ingresos
                    'rgba(255, 99, 132, 0.6)'  // Rojo para gastos
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true } }
        }
    });

    // Lógica del botón para cambiar entre barras y torta
    document.getElementById('btn-cambiar-grafico').addEventListener('click', function(e) {
        e.preventDefault();
        tipoGrafico = tipoGrafico === 'bar' ? 'pie' : 'bar'; // Alternamos
        
        miGrafico.destroy(); // Destruimos el gráfico anterior
        
        miGrafico = new Chart(ctx, {
            type: tipoGrafico,
            data: {
                labels: ['Ingresos', 'Gastos'],
                datasets: [{
                    label: 'Totales',
                    data: [datosGrafico.ingresos, datosGrafico.gastos],
                    backgroundColor: ['rgba(75, 192, 192, 0.6)', 'rgba(255, 99, 132, 0.6)'],
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                let value = context.raw || 0;
                                let percentage = ((value / total) * 100).toFixed(2) + '%';
                                return label + ': $' + value + ' (' + percentage + ')';
                            }
                        }
                    }
                }
            }
        });
    });
});