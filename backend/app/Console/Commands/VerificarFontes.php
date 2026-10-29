<?php

namespace App\Console\Commands;

use App\Models\VerificacaoExecucao;
use App\Models\VerificacaoHome;
use App\Services\Verificacao\VerificadorHome;
use Illuminate\Console\Command;
use Throwable;

/**
 * Dispara a rotina de verificação de fontes na mão.
 *
 * O caminho normal é o agendador (container `scheduler`, diário no horário de
 * VERIFICACAO_HORARIO); este comando é o gatilho manual — para uma rodada fora de
 * hora, para um recorte de páginas ou para testar um ajuste no verificador sem
 * esperar a madrugada. Ele mostra o que está acontecendo linha a linha — cada
 * título testado e o veredito — e fecha com o resumo da rodada.
 *
 * A rodada também limpa resíduos: página que ficou em `em andamento` por uma
 * execução interrompida volta a `pendente` antes de o trabalho começar.
 */
class VerificarFontes extends Command
{
    protected $signature = 'fontes:verificar
        {--paginas= : Limita a N páginas (padrão: VERIFICACAO_PAGINAS)}
        {--limite= : Máximo de títulos a testar nesta rodada}
        {--se-vencido : Só roda se houver página com prova vencida (resgate do agendador)}
        {--sem-pausa : Desliga as pausas de furtividade (use só em teste)}';

    protected $description = 'Verifica quais títulos das páginas da Home têm fonte.';

    /*
     * Quanto tempo uma execução ainda conta como "em curso".
     *
     * O agendador roda a tarefa de hora em hora e o `withoutOverlapping` só
     * protege a mesma entrada da agenda; a diária (04:00) e o resgate (04:20)
     * têm chaves de trava diferentes. Sem esta janela, um resgate poderia
     * começar por cima de uma rodada completa ainda em andamento — duas
     * varreduras disputando os mesmos títulos e o mesmo orçamento de rede. A
     * janela é folgada de propósito: uma rodada de 25 páginas com pausas passa
     * de uma hora, e é melhor esperar do que duplicar a carga.
     */
    private const HORAS_EXECUCAO_EM_CURSO = 4;

    public function handle(VerificadorHome $verificador): int
    {
        $paginas = $this->option('paginas') !== null ? (int) $this->option('paginas') : null;
        $limite = $this->option('limite') !== null ? (int) $this->option('limite') : null;
        $pausas = ! $this->option('sem-pausa');

        if ($this->option('se-vencido') && ! $this->deveResgatar()) {
            return self::SUCCESS;
        }

        $this->info('Verificação de fontes — começando.');

        if ($paginas !== null) {
            $this->line("  Páginas: {$paginas}");
        }

        if ($limite !== null) {
            $this->line("  Teto de títulos: {$limite}");
        }

        if (! $pausas) {
            $this->warn('  Pausas de furtividade DESLIGADAS (modo teste).');
        }

        $inicio = microtime(true);

        try {
            $execucao = $verificador->executar(
                log: fn (string $linha) => $this->line($linha),
                limitePaginas: $paginas,
                limiteTitulos: $limite,
                pausas: $pausas,
            );
        } catch (Throwable $excecao) {
            $this->newLine();
            $this->error('Falha na verificação: '.$excecao->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Concluído.');
        $this->table(['Métrica', 'Valor'], [
            ['Páginas processadas', $execucao->paginas_processadas],
            ['Títulos testados', $execucao->titulos_testados],
            ['Títulos com fonte', $execucao->titulos_com_fonte],
            ['Duração', $this->duracao((int) round(microtime(true) - $inicio))],
        ]);

        return self::SUCCESS;
    }

    /**
     * Decide se o resgate do agendador tem trabalho a fazer.
     *
     * Duas condições, nesta ordem: nenhuma execução em curso e ao menos uma
     * página com prova vencida. A primeira evita duplicar uma rodada completa
     * iniciada minutos antes (a diária e o resgate têm chaves de trava
     * diferentes); a segunda evita varredura sem motivo.
     *
     * O `schedule:work` **não recupera** tarefa diária perdida: `isDue()` olha o
     * minuto corrente da expressão cron, então um container recriado depois do
     * horário deixa o dia em branco. Foi exatamente isso que aconteceu depois do
     * `make aplicar` das 10h — as provas venceram e a Home passou a servir o
     * TMDB cru até a madrugada seguinte. O resgate horário fecha essa janela.
     */
    private function deveResgatar(): bool
    {
        $emCurso = VerificacaoExecucao::query()
            ->where('status', VerificacaoExecucao::STATUS_EM_ANDAMENTO)
            ->where('iniciada_em', '>', now()->subHours(self::HORAS_EXECUCAO_EM_CURSO))
            ->exists();

        if ($emCurso) {
            $this->line('Resgate: há uma execução em andamento — nada a fazer agora.');

            return false;
        }

        $validade = (int) config('services.verificacao.validade_horas', 24);
        $vencidas = VerificacaoHome::query()->comProvaVencida($validade)->count();

        if ($vencidas === 0) {
            $this->line('Resgate: nenhuma prova vencida — nada a fazer.');

            return false;
        }

        $this->info("Resgate: {$vencidas} página(s) com prova vencida — rodando a rodada completa.");

        return true;
    }

    /** Formata segundos como "1h 02min 03s", para o resumo ficar legível. */
    private function duracao(int $segundos): string
    {
        if ($segundos < 60) {
            return "{$segundos}s";
        }

        $minutos = intdiv($segundos, 60);
        $resto = $segundos % 60;

        if ($minutos < 60) {
            return sprintf('%dmin %02ds', $minutos, $resto);
        }

        $horas = intdiv($minutos, 60);
        $minutos %= 60;

        return sprintf('%dh %02dmin %02ds', $horas, $minutos, $resto);
    }
}
