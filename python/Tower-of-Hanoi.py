def hanoi_solver(n):
    rods = [list(range(n, 0, -1)), [], []]
    result = [' '.join(str(rod) for rod in rods)]

    def move(n, source, target, auxiliary):
        if n == 0:
            return
        move(n - 1, source, auxiliary, target)
        disk = rods[source].pop()
        rods[target].append(disk)
        result.append(' '.join(str(rod) for rod in rods))
        move(n - 1, auxiliary, target, source)

    move(n, 0, 2, 1)
    return '\n'.join(result)
