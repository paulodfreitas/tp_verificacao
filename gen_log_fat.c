#include <stdio.h>
#include <stdlib.h>
#include <math.h>

int main(int argc, char **argv) {
    int tamanhoCache = atoi(argv[1]),
        multiplicador = argc >= 3 ? atoi(argv[2]) : 1000,
        granularidade = argc >= 4 ? atoi(argv[3]) : 1,
        fatorial = 1;

    printf("MODULE log_fat (x)\n");
    printf("  VAR\n");
    printf("    log_fatx : array 1..%d of integer;\n", tamanhoCache);
    printf("  ASSIGN\n");
    for (int i = 1; i <= tamanhoCache; i+=granularidade) {
        fatorial = fatorial * i;
        printf("    init(log_fatx[%d]) := %.0lf;\n", i, log(fatorial)*multiplicador);
    }
    printf("  DEFINE\n");
    printf("    value := log_fatx;\n");
    return 0;
}